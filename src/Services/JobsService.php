<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;

/**
 * JobsService — reads SeAT-synced industry jobs (character + corporation) for
 * the current user's scope. Pure read; SeAT already polls these tables.
 *
 * Names (blueprint, product, installer, facility) are resolved with joins, not
 * per-row lookups. No ESI.
 */
class JobsService
{
    private CharacterResolver $resolver;

    public function __construct(?CharacterResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CharacterResolver();
    }

    /**
     * @return array{jobs:Collection, counts:array}
     */
    public function forUser(): array
    {
        $charIds = $this->resolver->characterIds();
        $corpIds = $this->resolver->ownCorporationIds();

        $jobs = collect();

        if (! empty($charIds)) {
            $jobs = $jobs->merge($this->characterJobs($charIds));
        }
        if (! empty($corpIds)) {
            $jobs = $jobs->merge($this->corporationJobs($corpIds));
        }

        // Newest-ending first within in-progress; completed after.
        $jobs = $jobs->sortBy('end_date')->values();

        $counts = [
            'active' => $jobs->where('status', 'active')->count(),
            'ready' => $jobs->where('status', 'ready')->count(),
            'delivered' => $jobs->where('status', 'delivered')->count(),
            'paused' => $jobs->where('status', 'paused')->count(),
            'total' => $jobs->count(),
        ];

        return ['jobs' => $jobs, 'counts' => $counts];
    }

    private function characterJobs(array $charIds): Collection
    {
        $names = DB::table('character_infos')->whereIn('character_id', $charIds)->pluck('name', 'character_id');

        return DB::table('character_industry_jobs as j')
            ->leftJoin('invTypes as bt', 'bt.typeID', '=', 'j.blueprint_type_id')
            ->leftJoin('invTypes as pt', 'pt.typeID', '=', 'j.product_type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 'j.facility_id')
            ->whereIn('j.character_id', $charIds)
            ->get([
                'j.job_id', 'j.character_id as owner_id', 'j.activity_id', 'j.runs', 'j.status',
                'j.start_date', 'j.end_date', 'j.installer_id', 'j.product_type_id',
                'bt.typeName as blueprint_name', 'pt.typeName as product_name', 'u.name as facility_name',
            ])
            ->map(fn ($r) => $this->normalize($r, 'character', $names[$r->installer_id] ?? null));
    }

    private function corporationJobs(array $corpIds): Collection
    {
        $jobs = DB::table('corporation_industry_jobs as j')
            ->leftJoin('invTypes as bt', 'bt.typeID', '=', 'j.blueprint_type_id')
            ->leftJoin('invTypes as pt', 'pt.typeID', '=', 'j.product_type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 'j.facility_id')
            ->whereIn('j.corporation_id', $corpIds)
            ->get([
                'j.job_id', 'j.corporation_id as owner_id', 'j.activity_id', 'j.runs', 'j.status',
                'j.start_date', 'j.end_date', 'j.installer_id', 'j.product_type_id',
                'bt.typeName as blueprint_name', 'pt.typeName as product_name', 'u.name as facility_name',
            ]);

        $installerIds = $jobs->pluck('installer_id')->unique()->filter()->all();
        $names = DB::table('character_infos')->whereIn('character_id', $installerIds)->pluck('name', 'character_id');

        return $jobs->map(fn ($r) => $this->normalize($r, 'corporation', $names[$r->installer_id] ?? null));
    }

    private function normalize($r, string $ownerType, ?string $installerName): array
    {
        return [
            'job_id' => (int) $r->job_id,
            'owner_type' => $ownerType,
            'activity_id' => (int) $r->activity_id,
            'activity_name' => IndustryActivity::name((int) $r->activity_id),
            'activity_icon' => IndustryActivity::icon((int) $r->activity_id),
            'blueprint_name' => $r->blueprint_name ?? 'Unknown blueprint',
            'product_name' => $r->product_name ?? null,
            'runs' => (int) $r->runs,
            'status' => $r->status,
            'start_date' => $r->start_date,
            'end_date' => $r->end_date,
            'installer_id' => (int) $r->installer_id,
            'installer_name' => $installerName ?? ('Character #' . (int) $r->installer_id),
            'facility_name' => $r->facility_name ?? null,
        ];
    }
}
