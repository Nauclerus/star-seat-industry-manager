<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\AssemblyLines;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\ServiceModules;

/**
 * StructureServices — which activities a structure can actually run, from the
 * service modules fitted in its service slots.
 *
 * SeAT already syncs a corporation's fitted modules: they are `corporation_assets`
 * rows whose `location_flag` is `ServiceSlot0`, `ServiceSlot1`, ... and whose
 * `location_id` is the structure itself. So this reads the same rows the Structures
 * page already relies on, and never calls ESI.
 *
 * Those typeIDs are the keys of the capability map the CCP import stores, so a
 * fitted Standup Manufacturing Plant I turns into assembly line 175, and line 175
 * says both "manufacturing" and which products it accepts.
 *
 * A structure with no industry service fitted has no activities at all. That is the
 * honest reading of the game: a citadel with only a Cloning Center cannot build
 * anything, however nice a citadel it is.
 */
class StructureServices
{
    /** @var array<int, array<int>>  service module typeID => assemblyLineIDs */
    private static ?array $installations = null;

    /** @var array<int, array>  assemblyLineID => decoded line */
    private static ?array $lines = null;

    /**
     * Fitted services and the activities they provide, keyed by structure id.
     *
     * Pass the security of each structure to have the activities split into the
     * ones that can actually run there and the ones whose service module cannot be
     * onlined in that system.
     *
     * @param  array<int>             $structureIds
     * @param  array<int, ?float>     $security  structure id => system security
     * @return array<int, array{services: array, lines: array, activities: array, blocked: array}>
     */
    public function forStructures(array $structureIds, array $security = []): array
    {
        $structureIds = array_values(array_unique(array_map('intval', $structureIds)));

        if (empty($structureIds)) {
            return [];
        }

        $fitted = $this->fittedServices($structureIds);
        $lines = $this->lines();
        $installations = $this->installations();

        $out = [];

        foreach ($structureIds as $structureId) {
            $services = $fitted[$structureId] ?? [];
            $systemSecurity = $security[$structureId] ?? null;
            $provided = [];

            foreach ($services as $service) {
                // A service module only provides its activity while it can be online,
                // and CCP publishes the highest security each one may be onlined in.
                $maxSecurity = ServiceModules::maxSecurity($service['type_id']);
                $service['max_security'] = $maxSecurity;

                foreach ($installations[$service['type_id']] ?? [] as $lineId) {
                    if (!isset($lines[$lineId])) {
                        continue;
                    }

                    // Two modules can provide the same line; the more restrictive one
                    // is the one that decides whether it runs here.
                    $line = $provided[$lineId] ?? $lines[$lineId];

                    if ($maxSecurity !== null) {
                        $line['max_security'] = isset($line['max_security'])
                            ? min($line['max_security'], $maxSecurity)
                            : $maxSecurity;
                    }

                    $provided[$lineId] = $line;
                }
            }

            $activities = [];
            $blocked = [];

            foreach ($provided as $line) {
                $activityId = (int) $line['activityID'];
                $max = $line['max_security'] ?? null;

                if ($max !== null && ($systemSecurity === null || $systemSecurity > (float) $max)) {
                    $blocked[$activityId] = (float) $max;

                    continue;
                }

                $activities[$activityId] = $line;
            }

            $out[$structureId] = [
                'services' => array_values($services),
                'lines' => $provided,
                'activities' => $activities,
                'blocked' => $blocked,
            ];
        }

        return $out;
    }

    /**
     * The modules in a structure's service slots, with the names SeAT already has.
     *
     * @param  array<int>  $structureIds
     * @return array<int, array<int, array{type_id:int, name:?string, quantity:int}>>
     */
    private function fittedServices(array $structureIds): array
    {
        if (!IndustryData::hasTable('corporation_assets')) {
            return [];
        }

        try {
            $rows = DB::table('corporation_assets')
                ->leftJoin('invTypes as t', 't.typeID', '=', 'corporation_assets.type_id')
                ->whereIn('corporation_assets.location_id', $structureIds)
                ->where('corporation_assets.location_flag', 'like', 'ServiceSlot%')
                ->groupBy('corporation_assets.location_id', 'corporation_assets.type_id', 't.typeName')
                ->get([
                    'corporation_assets.location_id',
                    'corporation_assets.type_id',
                    't.typeName',
                    DB::raw('SUM(corporation_assets.quantity) as total'),
                ]);
        } catch (\Throwable $e) {
            // Assets are not synced on this install: no structure can be proven able.
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->location_id][] = [
                'type_id' => (int) $row->type_id,
                'name' => $row->typeName,
                'quantity' => (int) $row->total,
            ];
        }

        return $out;
    }

    /**
     * Which of these structures SeAT holds asset rows for.
     *
     * A structure can be known without its contents being known: the access probe
     * records the structure, and only the corporation asset route records what is
     * fitted in it. Without asset rows nothing fitted can be claimed in either
     * direction, so a structure like that is a gap with an unknown inside rather
     * than a structure with nothing in it.
     *
     * @param  array<int>  $structureIds
     * @return array<int>
     */
    public function structuresWithAssets(array $structureIds): array
    {
        $structureIds = array_values(array_unique(array_map('intval', $structureIds)));

        if (empty($structureIds) || !IndustryData::hasTable('corporation_assets')) {
            return [];
        }

        try {
            $rows = DB::table('corporation_assets')
                ->whereIn('location_id', $structureIds)
                ->distinct()
                ->pluck('location_id');
        } catch (\Throwable $e) {
            return [];
        }

        return $rows->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, array<int>>
     */
    private function installations(): array
    {
        if (self::$installations !== null) {
            return self::$installations;
        }

        $map = [];

        if (IndustryData::isCapabilityInstalled()) {
            foreach (DB::table(IndustryData::TABLE_INSTALLATIONS)->get(['typeID', 'assemblyLineIDs']) as $row) {
                $map[(int) $row->typeID] = AssemblyLines::decode($row->assemblyLineIDs);
            }
        }

        return self::$installations = $map;
    }

    /**
     * @return array<int, array>
     */
    private function lines(): array
    {
        if (self::$lines !== null) {
            return self::$lines;
        }

        $map = [];

        if (IndustryData::isCapabilityInstalled()) {
            $rows = DB::table(IndustryData::TABLE_ASSEMBLY_LINES)
                ->get(['assemblyLineID', 'activityID', 'name', 'groupIDs', 'categoryIDs']);

            foreach ($rows as $row) {
                $map[(int) $row->assemblyLineID] = [
                    'assemblyLineID' => (int) $row->assemblyLineID,
                    'activityID' => (int) $row->activityID,
                    'name' => $row->name,
                    'groupIDs' => AssemblyLines::decode($row->groupIDs),
                    'categoryIDs' => AssemblyLines::decode($row->categoryIDs),
                ];
            }
        }

        return self::$lines = $map;
    }

    /**
     * Drop the loaded maps — after an SDE import, and between tests.
     */
    public static function flush(): void
    {
        self::$installations = null;
        self::$lines = null;
    }
}
