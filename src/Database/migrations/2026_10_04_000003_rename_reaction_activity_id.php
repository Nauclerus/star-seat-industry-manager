<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IndustryManager\Helpers\IndustryData;

/**
 * Reaction recipes were stamped with activityID 11.
 *
 * CCP has never used 11: industryActivities.jsonl calls reactions 9, live
 * character/corporation_industry_jobs rows carry 9, and the Fuzzwork fallback
 * copied 9 through verbatim. So the plugin's own recipe tables disagreed with
 * every job row they are joined against, and only the CCP import path produced
 * the wrong number.
 *
 * Both importers clear their tables before writing, so a table can never hold
 * both 9 and 11 reaction rows and this is a plain rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(IndustryData::TABLE_ACTIVITY)) {
            return;
        }

        foreach ([
            IndustryData::TABLE_ACTIVITY,
            IndustryData::TABLE_MATERIALS,
            IndustryData::TABLE_PRODUCTS,
            IndustryData::TABLE_SKILLS,
            IndustryData::TABLE_PROBABILITIES,
        ] as $table) {
            if (Schema::hasColumn($table, 'activityID')) {
                DB::table($table)->where('activityID', 11)->update(['activityID' => 9]);
            }
        }

        if (Schema::hasTable('industry_manager_production_runs')
            && Schema::hasColumn('industry_manager_production_runs', 'activity_id')) {
            DB::table('industry_manager_production_runs')
                ->where('activity_id', 11)
                ->update(['activity_id' => 9]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(IndustryData::TABLE_ACTIVITY)) {
            return;
        }

        foreach ([
            IndustryData::TABLE_ACTIVITY,
            IndustryData::TABLE_MATERIALS,
            IndustryData::TABLE_PRODUCTS,
            IndustryData::TABLE_SKILLS,
            IndustryData::TABLE_PROBABILITIES,
        ] as $table) {
            if (Schema::hasColumn($table, 'activityID')) {
                DB::table($table)->where('activityID', 9)->update(['activityID' => 11]);
            }
        }

        if (Schema::hasTable('industry_manager_production_runs')
            && Schema::hasColumn('industry_manager_production_runs', 'activity_id')) {
            DB::table('industry_manager_production_runs')
                ->where('activity_id', 9)
                ->update(['activity_id' => 11]);
        }
    }
};
