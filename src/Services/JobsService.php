<?php

namespace IndustryManager\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\JobFilter;
use IndustryManager\Helpers\JobScope;

/**
 * JobsService — reads SeAT-synced industry jobs for the current user's scope.
 * Pure read; SeAT already polls these tables.
 *
 * Names (blueprint, product, installer, facility, system, corporation) are
 * resolved with joins, not per-row lookups. No ESI.
 *
 * Two stages:
 *   1. Entitlement — which rows may this user see at all? Character jobs come
 *      from the user's linked characters, corporation jobs from the user's own
 *      corporations. Nothing outside that set is ever loaded.
 *   2. Presentation — JobScope tags each row with the groups it belongs to,
 *      and JobFilter decides which of them the current view shows.
 *
 * The second stage is what makes the personal/corporation/corpmates tabs
 * cheap: the entitlement query runs once and the tabs are counts over the same
 * result set.
 */
class JobsService
{
    private CharacterResolver $resolver;

    public function __construct(?CharacterResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new CharacterResolver();
    }

    /**
     * @return array{
     *     jobs: Collection,
     *     counts: array<string,int>,
     *     scope_counts: array<string,int>,
     *     facets: array{activities: Collection, statuses: Collection, structures: Collection, installers: Collection},
     *     context: array<string,array>
     * }
     */
    public function forUser(?JobFilter $filter = null): array
    {
        $filter = $filter ?? new JobFilter();

        $charIds = $this->resolver->characterIds();
        $corpIds = $this->resolver->ownCorporationIds();

        $context = [
            'character_ids' => $charIds,
            'corporation_ids' => $corpIds,
        ];

        // Stage 1: the entitlement set, before any UI filter.
        $visible = collect();

        if (! empty($charIds)) {
            $visible = $visible->merge($this->characterJobs($charIds, $context));
        }

        if (! empty($corpIds)) {
            $visible = $visible->merge($this->corporationJobs($corpIds, $context));
        }

        // Facets come from the entitlement set, not the filtered set, so the
        // dropdowns do not empty themselves as you filter.
        $facets = $this->facets($visible);

        // Stage 2.
        $jobs = $visible->filter(fn (array $job) => $filter->matches($job))
            ->sortBy('end_date')
            ->values();

        return [
            'jobs' => $jobs,
            'counts' => $this->counts($jobs),
            'scope_counts' => $this->scopeCounts($visible, $filter),
            'facets' => $facets,
            'context' => $context,
        ];
    }

    /**
     * Dashboard headline metrics, over the same entitlement set as the jobs
     * list (the user's own characters and their corporations).
     *
     *   running     — jobs ESI still reports as `active`, i.e. on the assembly
     *                 line right now. `ready` jobs are finished and await
     *                 delivery, so they are not running.
     *   month_cost  — sum of ESI's reported install cost (the `cost` column SeAT
     *                 syncs) for jobs started in the current calendar month.
     *   has_data    — false when the user is entitled to no jobs at all, so the
     *                 view can show an empty state instead of a bare zero.
     *
     * Read-only and failure-tolerant: a missing sync table or an unauthenticated
     * user yields the empty set rather than an error on the dashboard.
     *
     * @return array{running:int, month_cost:float, month_label:string, has_data:bool}
     */
    public function metrics(): array
    {
        $default = [
            'running' => 0,
            'month_cost' => 0.0,
            'month_label' => Carbon::now()->format('F Y'),
            'has_data' => false,
        ];

        try {
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfMonth();

            $running = 0;
            $monthCost = 0.0;
            $total = 0;

            // Each SeAT-synced job table uses its own owner column; the
            // character table is entitled by character_id, the corporation
            // table by corporation_id.
            $sources = [
                ['character_industry_jobs', 'character_id', $this->resolver->characterIds()],
                ['corporation_industry_jobs', 'corporation_id', $this->resolver->ownCorporationIds()],
            ];

            foreach ($sources as [$table, $ownerColumn, $ownerIds]) {
                if (empty($ownerIds)) {
                    continue;
                }

                $total += DB::table($table)->whereIn($ownerColumn, $ownerIds)->count();

                $running += DB::table($table)
                    ->whereIn($ownerColumn, $ownerIds)
                    ->where('status', 'active')
                    ->count();

                $monthCost += (float) DB::table($table)
                    ->whereIn($ownerColumn, $ownerIds)
                    ->whereBetween('start_date', [$start, $end])
                    ->sum('cost');
            }

            return [
                'running' => $running,
                'month_cost' => $monthCost,
                'month_label' => Carbon::now()->format('F Y'),
                'has_data' => $total > 0,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Industry Manager] dashboard job metrics failed: ' . $e->getMessage());

            return $default;
        }
    }

    /**
     * @param  int[] $charIds
     * @param  array $context
     */
    private function characterJobs(array $charIds, array $context): Collection
    {
        $names = DB::table('character_infos')->whereIn('character_id', $charIds)->pluck('name', 'character_id');

        $rows = DB::table('character_industry_jobs as j')
            ->leftJoin('invTypes as bt', 'bt.typeID', '=', 'j.blueprint_type_id')
            ->leftJoin('invTypes as pt', 'pt.typeID', '=', 'j.product_type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 'j.facility_id')
            ->leftJoin('solar_systems as s', 's.system_id', '=', 'u.solar_system_id')
            ->leftJoin('character_affiliations as ca', 'ca.character_id', '=', 'j.installer_id')
            ->leftJoin('corporation_infos as ci', 'ci.corporation_id', '=', 'ca.corporation_id')
            ->whereIn('j.character_id', $charIds)
            ->get([
                'j.job_id', 'j.character_id as owner_id', 'j.activity_id', 'j.runs', 'j.status',
                'j.start_date', 'j.end_date', 'j.installer_id', 'j.facility_id', 'j.product_type_id',
                'bt.typeName as blueprint_name', 'pt.typeName as product_name',
                'u.name as facility_name', 's.name as system_name', 'ci.name as corporation_name',
            ]);

        // Installers outside the user's own characters still deserve a name.
        $extra = DB::table('character_infos')
            ->whereIn('character_id', $rows->pluck('installer_id')->unique()->all())
            ->pluck('name', 'character_id');
        $names = $names->merge($extra);

        return $rows->map(function ($r) use ($context, $names) {
            return $this->normalize($r, JobScope::SOURCE_CHARACTER, $context, $names);
        });
    }

    /**
     * @param  int[] $corpIds
     * @param  array $context
     */
    private function corporationJobs(array $corpIds, array $context): Collection
    {
        $rows = DB::table('corporation_industry_jobs as j')
            ->leftJoin('invTypes as bt', 'bt.typeID', '=', 'j.blueprint_type_id')
            ->leftJoin('invTypes as pt', 'pt.typeID', '=', 'j.product_type_id')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 'j.facility_id')
            ->leftJoin('solar_systems as s', 's.system_id', '=', 'u.solar_system_id')
            ->leftJoin('corporation_infos as ci', 'ci.corporation_id', '=', 'j.corporation_id')
            ->whereIn('j.corporation_id', $corpIds)
            ->get([
                'j.job_id', 'j.corporation_id as owner_id', 'j.activity_id', 'j.runs', 'j.status',
                'j.start_date', 'j.end_date', 'j.installer_id', 'j.facility_id', 'j.product_type_id',
                'bt.typeName as blueprint_name', 'pt.typeName as product_name',
                'u.name as facility_name', 's.name as system_name', 'ci.name as corporation_name',
            ]);

        $names = DB::table('character_infos')
            ->whereIn('character_id', $rows->pluck('installer_id')->unique()->filter()->all())
            ->pluck('name', 'character_id');

        return $rows->map(function ($r) use ($context, $names) {
            return $this->normalize($r, JobScope::SOURCE_CORPORATION, $context, $names);
        });
    }

    private function normalize($r, string $ownerType, array $context, Collection $names): array
    {
        $ownerId = (int) $r->owner_id;
        $installerId = (int) $r->installer_id;

        return [
            'job_id' => (int) $r->job_id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'scopes' => JobScope::scopesFor($ownerType, $ownerId, $installerId, $context),
            'activity_id' => (int) $r->activity_id,
            'activity_name' => IndustryActivity::name((int) $r->activity_id),
            'activity_icon' => IndustryActivity::icon((int) $r->activity_id),
            'blueprint_name' => $r->blueprint_name ?? 'Unknown blueprint',
            'product_name' => $r->product_name ?? null,
            'runs' => (int) $r->runs,
            'status' => $r->status,
            'start_date' => $r->start_date,
            'end_date' => $r->end_date,
            'installer_id' => $installerId,
            'installer_name' => $names[$installerId] ?? ('Character #' . $installerId),
            'facility_id' => (int) $r->facility_id,
            'facility_name' => $r->facility_name ?? null,
            'system_name' => $r->system_name ?? null,
            'corporation_name' => $r->corporation_name ?? null,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function counts(Collection $jobs): array
    {
        $counts = [];
        foreach (JobFilter::STATUSES as $status) {
            $counts[$status] = $jobs->where('status', $status)->count();
        }

        $counts['total'] = $jobs->count();

        return $counts;
    }

    /**
     * How many jobs each scope tab would show, honouring the non-scope filters.
     *
     * @return array<string,int>
     */
    private function scopeCounts(Collection $visible, JobFilter $filter): array
    {
        $counts = [];

        foreach (JobScope::all() as $scope) {
            $counts[$scope] = $visible->filter(
                fn (array $job) => $filter->matchesIgnoringScope($job)
                    && JobScope::matches($job['scopes'], $scope)
            )->count();
        }

        return $counts;
    }

    /**
     * Distinct values present in the entitlement set, for the filter dropdowns.
     *
     * @return array{activities:Collection, statuses:Collection, structures:Collection, installers:Collection}
     */
    private function facets(Collection $visible): array
    {
        $activities = $visible
            ->map(fn (array $job) => ['id' => $job['activity_id'], 'name' => $job['activity_name'], 'icon' => $job['activity_icon']])
            ->unique('id')
            ->sortBy('name')
            ->values();

        $statuses = $visible
            ->map(fn (array $job) => ['id' => $job['status'], 'name' => ucfirst($job['status'])])
            ->unique('id')
            ->sortBy('id')
            ->values();

        $structures = $visible
            ->filter(fn (array $job) => $job['facility_id'] > 0)
            ->map(fn (array $job) => [
                'id' => $job['facility_id'],
                'name' => $job['facility_name'] ?? ('Structure #' . $job['facility_id']),
                'system' => $job['system_name'],
            ])
            ->unique('id')
            ->sortBy('name')
            ->values();

        $installers = $visible
            ->map(fn (array $job) => ['id' => $job['installer_id'], 'name' => $job['installer_name']])
            ->unique('id')
            ->sortBy('name')
            ->values();

        return compact('activities', 'statuses', 'structures', 'installers');
    }
}
