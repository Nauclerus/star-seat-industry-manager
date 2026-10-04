<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\RigAttributes;
use IndustryManager\Helpers\StructureTypes;

/**
 * StructureService — the user's corporation industry structures (Engineering
 * Complexes + Refineries) with their fitted rigs and the bonus magnitudes those
 * rigs grant.
 *
 * The structure list is one targeted query. The rig bonuses come from
 * StructureIndustryRigs, which reads SeAT's `CorporationStructure::rig_slots` —
 * the same source Structure Manager uses for doctrine compliance — so there is
 * no ESI call and no new scope.
 *
 * Bonuses are security-adjusted using the multiplier stored on the rig itself
 * (2355 / 2356 / 2357), falling back to the classic 1.0 / 1.9 / 2.1 table only
 * when a rig carries no multiplier attribute.
 */
class StructureService
{
    private CharacterResolver $resolver;

    public function __construct(?CharacterResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CharacterResolver();
    }

    /**
     * @return Collection<int,array>
     */
    public function forUser(): Collection
    {
        $corpIds = $this->resolver->ownCorporationIds();
        if (empty($corpIds)) {
            return collect();
        }

        $structures = DB::table('corporation_structures as s')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 's.structure_id')
            ->leftJoin('mapDenormalize as m', 'm.itemID', '=', 's.system_id')
            ->whereIn('s.corporation_id', $corpIds)
            ->whereIn('s.type_id', StructureTypes::INDUSTRY)
            ->get([
                's.structure_id',
                's.type_id',
                't.typeName as type_name',
                'u.name as struct_name',
                'm.itemName as system_name',
                'm.security',
            ]);

        if ($structures->isEmpty()) {
            return collect();
        }

        return $structures->map(function ($s) {
            $security = $s->security !== null ? (float) $s->security : null;
            $fit = StructureIndustryRigs::forStructure((int) $s->structure_id, $security);

            return [
                'structure_id' => (int) $s->structure_id,
                'name' => $s->struct_name ?: ('Structure ' . $s->structure_id),
                'type_id' => (int) $s->type_id,
                'type_name' => $s->type_name ?: StructureTypes::name((int) $s->type_id),
                'class' => StructureTypes::className((int) $s->type_id),
                'size' => StructureTypes::sizeClass((int) $s->type_id),
                'system_name' => $s->system_name ?: 'Unknown',
                'security' => $security !== null ? round($security, 1) : null,
                'security_class' => RigAttributes::securityClass($security),
                'security_band' => $fit['band'],
                'security_multiplier' => $fit['multiplier'],
                'rigs' => $fit['rigs'],
                'me_bonus' => $fit['me_bonus'],
                'te_bonus' => $fit['te_bonus'],
                'cost_bonus' => $fit['cost_bonus'],
                'source' => $fit['source'],
            ];
        })->sortBy('name')->values();
    }

    /**
     * One structure, scoped to what the current user may see, with its resolved
     * rig fit. Used by the calculator when a structure is chosen so the material
     * modifier reflects the actual fit.
     *
     * @return array|null  null when the structure is not visible to this user
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

        if (!$structure || !$this->resolver->canViewCorporation((int) $structure->corporation_id)) {
            return null;
        }

        $security = $structure->security !== null ? (float) $structure->security : null;
        $fit = StructureIndustryRigs::forStructure($structureId, $security);

        return [
            'structure_id' => $structureId,
            'name' => $structure->struct_name ?: ('Structure ' . $structureId),
            'type_name' => $structure->type_name ?: StructureTypes::name((int) $structure->type_id),
            'system_name' => $structure->system_name ?: 'Unknown',
            'security' => $security !== null ? round($security, 1) : null,
            'security_class' => RigAttributes::securityClass($security),
            'band' => $fit['band'],
            'multiplier' => $fit['multiplier'],
            'me_bonus' => $fit['me_bonus'],
            'te_bonus' => $fit['te_bonus'],
            'cost_bonus' => $fit['cost_bonus'],
            'source' => $fit['source'],
        ];
    }
}
