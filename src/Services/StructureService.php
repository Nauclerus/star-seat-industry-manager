<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\RigAttributes;
use IndustryManager\Helpers\StructureBonuses;
use IndustryManager\Helpers\StructureTypes;

/**
 * StructureService — the structures the user's alliance can produce in, with the
 * service modules fitted in them, the rigs fitted in those, and the bonuses the
 * combination actually grants.
 *
 * Three separate questions, answered from three separate sources:
 *
 *   can a job happen here       the service modules in the structure's ServiceSlot*
 *                               asset locations, mapped through CCP's assembly lines
 *   can we get at it            what SeAT already learned from the ACL-gated
 *                               /universe/structures/ route
 *   what does it save           the rigs fitted in it, plus the bonus the structure
 *                               type itself publishes
 *
 * Structures are an alliance-wide question — a run can be done in a corp next door —
 * so the candidate set is the user's own corps plus their alliance's corps, tagged
 * with which it is. Jobs are not, and stay scoped to the user's own characters.
 *
 * No ESI call and no new scope: the structures, their fitted modules and the ACL
 * results are all tables SeAT already syncs.
 */
class StructureService
{
    private CharacterResolver $resolver;

    private StructureServices $services;

    private StructureAccess $access;

    public function __construct(
        ?CharacterResolver $resolver = null,
        ?StructureServices $services = null,
        ?StructureAccess $access = null
    ) {
        $this->resolver = $resolver ?? new CharacterResolver();
        $this->services = $services ?? new StructureServices();
        $this->access = $access ?? new StructureAccess();
    }

    /**
     * @return Collection<int,array>
     */
    public function forUser(): Collection
    {
        $corpIds = $this->resolver->allianceCorporationIds();

        if (empty($corpIds)) {
            return collect();
        }

        $ownCorpIds = $this->resolver->ownCorporationIds();
        $capability = IndustryData::isCapabilityInstalled();

        $structures = $this->candidates($corpIds, $capability);

        if ($structures->isEmpty()) {
            return collect();
        }

        $ids = $structures->pluck('structure_id')->map(fn ($id) => (int) $id)->all();
        $securityById = [];
        $withContents = $this->services->structuresWithKnownContents($ids);

        foreach ($structures as $s) {
            $securityById[(int) $s->structure_id] = $s->security !== null ? (float) $s->security : null;
        }

        $capabilities = $this->services->forStructures($ids, $securityById);
        $statuses = $this->access->statuses($this->resolver->characterIds(), $ids);

        return $structures
            ->map(function ($s) use ($capabilities, $statuses, $ownCorpIds, $withContents) {
                $structureId = (int) $s->structure_id;
                $security = $s->security !== null ? (float) $s->security : null;
                $capability = $capabilities[$structureId]
                    ?? ['services' => [], 'lines' => [], 'activities' => [], 'blocked' => []];
                $status = $statuses[$structureId] ?? ['status' => StructureAccess::UNKNOWN, 'denied' => []];

                return [
                    'structure_id' => $structureId,
                    'corporation_id' => (int) $s->corporation_id,
                    'name' => $s->struct_name ?: ('Structure ' . $structureId),
                    'type_id' => (int) $s->type_id,
                    'type_name' => $s->type_name ?: StructureTypes::name((int) $s->type_id),
                    'class' => StructureTypes::className((int) $s->type_id),
                    'size' => StructureTypes::sizeClass((int) $s->type_id),
                    'system_name' => $s->system_name ?: 'Unknown',
                    'security' => $security !== null ? round($security, 1) : null,
                    'security_class' => RigAttributes::securityClass($security),
                    'scope' => in_array((int) $s->corporation_id, $ownCorpIds, true) ? 'own' : 'alliance',
                    'services' => $capability['services'],
                    'lines' => $capability['lines'],
                    'activities' => $capability['activities'],
                    'access' => $status['status'],
                    'access_denied' => $status['denied'],
                    'contents_known' => in_array($structureId, $withContents, true),
                    'bonuses' => $this->bonusesFor((int) $s->type_id),
                    'blocked' => $capability['blocked'] ?? [],
                ];
            })
            ->filter(function (array $st) {
                // A service host with nothing fitted is a gap worth showing: it is a
                // place an industry job could happen once the right module is in it.
                // What is not shown is a structure that cannot hold a service module
                // at all, such as a moon drill.
                return IndustryData::isCapabilityInstalled()
                    ? !empty($st['activities']) || StructureTypes::isServiceHost((int) $st['type_id'])
                    : true;
            })
            ->map(function (array $st) {
                $fit = StructureIndustryRigs::forStructure($st['structure_id'], $st['security']);

                return $st + [
                    'security_band' => $fit['band'],
                    'security_multiplier' => $fit['multiplier'],
                    'rigs' => $fit['rigs'],
                    'fit' => $fit,
                    'me_bonus' => $fit['me_bonus'],
                    'te_bonus' => $fit['te_bonus'],
                    'cost_bonus' => $fit['cost_bonus'],
                    'source' => $fit['source'],
                ];
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * The structures the alliance corporations own.
     *
     * SeAT learns about a structure in two ways. The corporation structure route
     * gives the structure, the corporation and the system it stands in. The access
     * probe records a structure in universe_structures even for a corporation whose
     * own structure list has never been read. Both are structures the alliance has,
     * so both belong in the comparison; what the second one lacks is the asset data
     * that says what is fitted inside, which is recorded as such rather than
     * guessed at.
     *
     * @param  int[]  $corpIds
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function candidates(array $corpIds, bool $capability): Collection
    {
        $query = DB::table('corporation_structures as s')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 's.structure_id')
            ->leftJoin('mapDenormalize as m', 'm.itemID', '=', 's.system_id')
            ->whereIn('s.corporation_id', $corpIds);

        // Without the capability map there is nothing to read a structure's
        // services through, so fall back to the curated industry structure types.
        if (!$capability) {
            $query->whereIn('s.type_id', StructureTypes::INDUSTRY);
        }

        $structures = $query->get([
            's.structure_id',
            's.corporation_id',
            's.type_id',
            't.typeName as type_name',
            'u.name as struct_name',
            'm.itemName as system_name',
            'm.security',
        ]);

        $seen = $structures->pluck('structure_id')->map(fn ($id) => (int) $id)->all();

        $probe = DB::table('universe_structures as u')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'u.type_id')
            ->leftJoin('mapDenormalize as m', 'm.itemID', '=', 'u.solar_system_id')
            ->whereIn('u.owner_id', $corpIds)
            ->whereNotNull('u.type_id');

        if (!empty($seen)) {
            $probe->whereNotIn('u.structure_id', $seen);
        }

        if (!$capability) {
            $probe->whereIn('u.type_id', StructureTypes::INDUSTRY);
        }

        try {
            foreach ($probe->get([
                'u.structure_id',
                'u.owner_id as corporation_id',
                'u.type_id',
                't.typeName as type_name',
                'u.name as struct_name',
                'm.itemName as system_name',
                'm.security',
            ]) as $row) {
                $structures->push($row);
            }
        } catch (\Throwable $e) {
            // An install without the probe table simply has the one source.
        }

        return $structures;
    }

    /**
     * One structure, scoped to what the current user may use, with its fitted
     * services and resolved rig fit. Used by the calculator when a structure is
     * chosen, so the modifiers reflect the actual fit rather than a guess.
     *
     * @return array|null  null when the structure is not available to this user
     */
    public function fitForStructure(int $structureId): ?array
    {
        $structure = DB::table('corporation_structures as s')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 's.structure_id')
            ->leftJoin('mapDenormalize as m', 'm.itemID', '=', 's.system_id')
            ->where('s.structure_id', $structureId)
            ->first([
                's.structure_id',
                's.corporation_id',
                's.type_id',
                't.typeName as type_name',
                'u.name as struct_name',
                'm.itemName as system_name',
                'm.security',
            ]);

        if (!$structure) {
            // Known from the access probe, with no corporation structure row behind it.
            $structure = DB::table('universe_structures as u')
                ->leftJoin('invTypes as t', 't.typeID', '=', 'u.type_id')
                ->leftJoin('mapDenormalize as m', 'm.itemID', '=', 'u.solar_system_id')
                ->where('u.structure_id', $structureId)
                ->first([
                    'u.structure_id',
                    'u.owner_id as corporation_id',
                    'u.type_id',
                    't.typeName as type_name',
                    'u.name as struct_name',
                    'm.itemName as system_name',
                    'm.security',
                ]);
        }

        if (!$structure || !$this->resolver->canUseCorporation((int) $structure->corporation_id)) {
            return null;
        }

        $security = $structure->security !== null ? (float) $structure->security : null;
        $fit = StructureIndustryRigs::forStructure($structureId, $security);
        $capability = $this->services->forStructures([$structureId], [$structureId => $security])[$structureId]
            ?? ['services' => [], 'lines' => [], 'activities' => [], 'blocked' => []];
        $status = $this->access->statuses($this->resolver->characterIds(), [$structureId])[$structureId]
            ?? ['status' => StructureAccess::UNKNOWN, 'denied' => []];

        return [
            'structure_id' => $structureId,
            'corporation_id' => (int) $structure->corporation_id,
            'name' => $structure->struct_name ?: ('Structure ' . $structureId),
            'type_name' => $structure->type_name ?: StructureTypes::name((int) $structure->type_id),
            'system_name' => $structure->system_name ?: 'Unknown',
            'security' => $security !== null ? round($security, 1) : null,
            'security_class' => RigAttributes::securityClass($security),
            'band' => $fit['band'],
            'multiplier' => $fit['multiplier'],
            'fit' => $fit,
            'type_id' => (int) $structure->type_id,
            'services' => $capability['services'],
            'lines' => $capability['lines'],
            'activities' => $capability['activities'],
            'access' => $status['status'],
            'bonuses' => $this->bonusesFor((int) $structure->type_id),
            'blocked' => $capability['blocked'] ?? [],
            'me_bonus' => $fit['me_bonus'],
            'te_bonus' => $fit['te_bonus'],
            'cost_bonus' => $fit['cost_bonus'],
            'source' => $fit['source'],
        ];
    }

    /**
     * What each activity is worth in this structure type, straight from the
     * attributes the type publishes.
     *
     * @return array<int, array{material:float, cost:float, time:float}>
     */
    private function bonusesFor(int $typeId): array
    {
        $out = [];

        foreach (array_keys(StructureBonuses::ACTIVITY_ATTRIBUTES) as $activityId) {
            $bonus = StructureBonuses::for($typeId, $activityId);

            if ($bonus !== ['material' => 1.0, 'cost' => 1.0, 'time' => 1.0]) {
                $out[$activityId] = $bonus;
            }
        }

        return $out;
    }
}
