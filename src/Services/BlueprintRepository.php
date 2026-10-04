<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BlueprintRepository — fast, scoped reads of the SeAT-synced
 * character_blueprints + corporation_blueprints tables.
 *
 * Performance notes (lessons from auditing Blueprint Manager):
 *   - Uses the query builder with a single join to invTypes/invGroups per
 *     source table. No Eloquent hydration of thousands of rows.
 *   - Owner names are preloaded with two keyed lookups (character_infos,
 *     corporation_infos) and mapped in memory — NOT looked up per row.
 *     (Blueprint Manager's per-row CorporationAsset::first() in a .map() loop
 *     was the N+1 we are deliberately avoiding.)
 *
 * BPO vs BPC: runs == -1 means BPO (matches Blueprint Manager's proven test).
 */
class BlueprintRepository
{
    private CharacterResolver $resolver;

    public function __construct(?CharacterResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CharacterResolver();
    }

    /**
     * Normalised blueprint rows visible to the current user.
     *
     * Options:
     *   - owner_type: 'character' | 'corporation' | null (both, default)
     *   - corporation_id: restrict to one corp (permission-checked)
     *   - character_id: restrict to one of the user's characters
     *
     * Each row: type_id, type_name, group_id, me, te, runs, is_bpo, quantity,
     *           owner_id, owner_name, owner_type, location_id
     *
     * @return Collection<int,array>
     */
    public function forUser(array $opts = []): Collection
    {
        $ownerType = $opts['owner_type'] ?? null;
        $onlyCorp = isset($opts['corporation_id']) ? (int) $opts['corporation_id'] : null;
        $onlyChar = isset($opts['character_id']) ? (int) $opts['character_id'] : null;

        $rows = collect();

        if ($ownerType !== 'corporation') {
            $rows = $rows->merge($this->characterBlueprints($onlyChar));
        }

        if ($ownerType !== 'character') {
            $rows = $rows->merge($this->corporationBlueprints($onlyCorp));
        }

        return $rows->values();
    }

    /**
     * @return Collection<int,array>
     */
    private function characterBlueprints(?int $onlyChar): Collection
    {
        $charIds = $this->resolver->characterIds();

        if ($onlyChar !== null) {
            // Only allow filtering to a character the user actually owns.
            $charIds = in_array($onlyChar, $charIds, true) ? [$onlyChar] : [];
        }

        if (empty($charIds)) {
            return collect();
        }

        $names = DB::table('character_infos')
            ->whereIn('character_id', $charIds)
            ->pluck('name', 'character_id');

        return DB::table('character_blueprints as b')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'b.type_id')
            ->whereIn('b.character_id', $charIds)
            ->get([
                'b.type_id',
                'b.character_id as owner_id',
                'b.material_efficiency as me',
                'b.time_efficiency as te',
                'b.runs',
                'b.quantity',
                'b.location_id',
                't.typeName as type_name',
                't.groupID as group_id',
            ])
            ->map(function ($r) use ($names) {
                return $this->normalize($r, 'character', $names[$r->owner_id] ?? ('Character #' . $r->owner_id));
            });
    }

    /**
     * @return Collection<int,array>
     */
    private function corporationBlueprints(?int $onlyCorp): Collection
    {
        // Default scope is the user's OWN corps (never the all-corps firehose,
        // even for admins). An explicit corporation_id may widen to a specific
        // corp, but only if the user is allowed to see it.
        if ($onlyCorp !== null) {
            if (! $this->resolver->canViewCorporation($onlyCorp)) {
                return collect();
            }
            $corpIds = [$onlyCorp];
        } else {
            $corpIds = $this->resolver->ownCorporationIds();
        }

        if (empty($corpIds)) {
            return collect();
        }

        $names = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corpIds)
            ->pluck('name', 'corporation_id');

        return DB::table('corporation_blueprints as b')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'b.type_id')
            ->whereIn('b.corporation_id', $corpIds)
            ->get([
                'b.type_id',
                'b.corporation_id as owner_id',
                'b.material_efficiency as me',
                'b.time_efficiency as te',
                'b.runs',
                'b.quantity',
                'b.location_id',
                't.typeName as type_name',
                't.groupID as group_id',
            ])
            ->map(function ($r) use ($names) {
                return $this->normalize($r, 'corporation', $names[$r->owner_id] ?? ('Corporation #' . $r->owner_id));
            });
    }

    /**
     * Shape a raw row into the normalised DTO.
     */
    private function normalize($r, string $ownerType, string $ownerName): array
    {
        $runs = (int) $r->runs;

        return [
            'type_id' => (int) $r->type_id,
            'type_name' => $r->type_name ?? ('Type #' . $r->type_id),
            'group_id' => $r->group_id !== null ? (int) $r->group_id : null,
            'me' => (int) $r->me,
            'te' => (int) $r->te,
            'runs' => $runs,
            'is_bpo' => $runs === -1,
            'quantity' => (int) $r->quantity,
            'owner_id' => (int) $r->owner_id,
            'owner_name' => $ownerName,
            'owner_type' => $ownerType,
            'location_id' => (int) $r->location_id,
        ];
    }

    /**
     * Aggregate the flat rows by blueprint type for a compact library view:
     * one row per type with copy/original counts and ME/TE ranges.
     *
     * @return Collection<int,array>
     */
    public function groupByType(Collection $rows): Collection
    {
        return $rows->groupBy('type_id')->map(function ($group) {
            $first = $group->first();
            $bpos = $group->where('is_bpo', true);
            $bpcs = $group->where('is_bpo', false);

            return [
                'type_id' => $first['type_id'],
                'type_name' => $first['type_name'],
                'group_id' => $first['group_id'],
                'total' => $group->count(),
                'bpo_count' => $bpos->count(),
                'bpc_count' => $bpcs->count(),
                'best_me' => $group->max('me'),
                'best_te' => $group->max('te'),
                'owners' => $group->pluck('owner_name')->unique()->values()->all(),
            ];
        })->values()->sortBy('type_name')->values();
    }
}
