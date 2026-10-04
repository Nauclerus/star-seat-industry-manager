<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;

/**
 * StructureAccess — whether a character is on a structure's ACL, using what SeAT
 * has already found out.
 *
 * CCP does not publish ACL lists. What they do publish is a route that *is* the
 * test: `/universe/structures/{structure_id}/` returns the structure "if you are on
 * the ACL", and Forbidden otherwise. SeAT core already calls it — the Citadel job
 * resolves every facility id that turns up in a character's industry jobs, assets,
 * contracts and market orders — and keeps the two halves of the answer in two
 * tables:
 *
 *   universe_structures    a character got a 200 for this structure
 *   citadel_access_cache   a character got a 403 for this structure, and is not
 *                          asked again for four weeks
 *
 * Both are read-only here. The honest reading of them:
 *
 *   - a structure in universe_structures was reachable by one of the tracked
 *     characters, which is what "we can dock there and use it" means in practice;
 *   - a citadel_access_cache row for this character is a live denial;
 *   - a structure in neither table has simply never been asked about, so it is
 *     unknown rather than denied.
 *
 * Docking access and industry access are also not the same thing in EVE: the
 * industry window will offer a facility you cannot physically dock at. So this
 * answers "can we get at this structure", and the fitted service modules answer
 * "can a job happen here".
 */
class StructureAccess
{
    public const KNOWN = 'known';
    public const DENIED = 'denied';
    public const UNKNOWN = 'unknown';

    /** @var array<int, bool>  structure_id => present in universe_structures */
    private static ?array $resolved = null;


    /**
     * Was this structure ever resolved for a tracked character?
     *
     * @param  array<int>  $structureIds
     * @return array<int, bool>
     */
    public function resolvedFor(array $structureIds): array
    {
        $structureIds = array_values(array_unique(array_map('intval', $structureIds)));

        if (empty($structureIds)) {
            return [];
        }

        $known = $this->resolvedSet();

        $out = [];

        foreach ($structureIds as $structureId) {
            $out[$structureId] = isset($known[$structureId]);
        }

        return $out;
    }

    /**
     * How this set of characters stands with this structure.
     *
     * Known and not denied for at least one of them is the usable case: the run is
     * done by one character, and that character is the one the assigner picks.
     *
     * @param  array<int>  $characterIds
     * @return array{status:string, denied: array<int>}
     */
    public function status(array $characterIds, int $structureId): array
    {
        $characterIds = array_values(array_unique(array_map('intval', $characterIds)));

        if (!$this->resolvedFor([$structureId])[$structureId]) {
            return ['status' => self::UNKNOWN, 'denied' => []];
        }

        $denied = $this->deniedFor($characterIds, $structureId);

        return [
            'status' => count($denied) >= count($characterIds) && !empty($characterIds)
                ? self::DENIED
                : self::KNOWN,
            'denied' => $denied,
        ];
    }

    /**
     * @param  array<int>  $structureIds
     * @return array<int, array{status:string, denied: array<int>}>  keyed by structure id
     */
    public function statuses(array $characterIds, array $structureIds): array
    {
        $characterIds = array_values(array_unique(array_map('intval', $characterIds)));
        $structureIds = array_values(array_unique(array_map('intval', $structureIds)));

        $resolved = $this->resolvedFor($structureIds);
        $out = [];

        foreach ($structureIds as $structureId) {
            if (!$resolved[$structureId]) {
                $out[$structureId] = ['status' => self::UNKNOWN, 'denied' => []];

                continue;
            }

            $denied = $this->deniedFor($characterIds, $structureId);

            $out[$structureId] = [
                'status' => count($denied) >= count($characterIds) && !empty($characterIds)
                    ? self::DENIED
                    : self::KNOWN,
                'denied' => $denied,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int>  $characterIds
     * @return array<int>  characters with a live denial for this structure
     */
    private function deniedFor(array $characterIds, int $structureId): array
    {
        if (empty($characterIds) || !IndustryData::hasTable('citadel_access_cache')) {
            return [];
        }

        try {
            $rows = DB::table('citadel_access_cache')
                ->where('citadel_id', $structureId)
                ->whereIn('character_id', $characterIds)
                ->where('next_allowed_access', '>=', now())
                ->get(['character_id']);
        } catch (\Throwable $e) {
            return [];
        }

        return $rows->pluck('character_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, bool>
     */
    private function resolvedSet(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $set = [];

        if (IndustryData::hasTable('universe_structures')) {
            try {
                foreach (DB::table('universe_structures')->pluck('structure_id') as $id) {
                    $set[(int) $id] = true;
                }
            } catch (\Throwable $e) {
                $set = [];
            }
        }

        return self::$resolved = $set;
    }

    /**
     * Drop the memos — between tests, and after a refresh.
     */
    public static function flush(): void
    {
        self::$resolved = null;
    }
}
