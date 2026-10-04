<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\RecipeSources\CcpJsonlSource;
use IndustryManager\Services\RecipeSources\RecipeSourceResolver;

/**
 * StatusService — system/health facts about the plugin's data, reused by the
 * Settings page and the Diagnostic Health/Data-Integrity tabs.
 *
 * Row counts are cached briefly so loading either page doesn't repeatedly
 * COUNT(*) the large industry tables.
 */
class StatusService
{
    private const COUNT_TTL = 300; // 5 min

    /**
     * @return array{installed:bool, version:?string, tables:array}
     */
    public function sdeStatus(): array
    {
        $tables = [];
        foreach (IndustryData::TABLES as $t) {
            $exists = IndustryData::hasTable($t);
            $tables[$t] = [
                'exists' => $exists,
                'rows' => $exists ? $this->countTable($t) : 0,
            ];
        }

        $source = IndustryData::recipeSource();

        return [
            'installed' => IndustryData::isInstalled(),
            'version' => IndustryData::recipeVersion(),
            'core_version' => $this->sdeVersion(),
            'source' => $source,
            'source_label' => RecipeSourceResolver::make($source)->label(),
            'preferred_source' => RecipeSourceResolver::preferredName(),
            'ccp_available' => CcpJsonlSource::available(),
            'tables' => $tables,
        ];
    }

    /**
     * Coverage sanity: how many manufacturing blueprints have a recipe, and the
     * material/product row counts. Helps confirm the SDE import succeeded.
     *
     * @return array
     */
    public function recipeCoverage(): array
    {
        if (! IndustryData::isInstalled()) {
            return ['manufacturing_blueprints' => 0, 'reaction_formulas' => 0, 'material_rows' => 0];
        }

        return Cache::remember('im:coverage:' . IndustryData::recipeVersion(), self::COUNT_TTL, function () {
            $mfg = (int) DB::table(IndustryData::TABLE_PRODUCTS)
                ->where('activityID', IndustryActivity::MANUFACTURING)->distinct()->count('typeID');
            $rx = (int) DB::table(IndustryData::TABLE_PRODUCTS)
                ->where('activityID', IndustryActivity::REACTIONS)->distinct()->count('typeID');
            $mats = (int) DB::table(IndustryData::TABLE_MATERIALS)->count();

            return [
                'manufacturing_blueprints' => $mfg,
                'reaction_formulas' => $rx,
                'material_rows' => $mats,
            ];
        });
    }

    public function sdeVersion(): ?string
    {
        try {
            $v = setting('installed_sde', true);

            return $v ? (string) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function cacheDriver(): string
    {
        try {
            return (string) config('cache.default', 'unknown');
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    private function countTable(string $table): int
    {
        return Cache::remember('im:rowcount:' . $table . ':' . IndustryData::recipeVersion(), self::COUNT_TTL, function () use ($table) {
            try {
                return (int) DB::table($table)->count();
            } catch (\Throwable $e) {
                return 0;
            }
        });
    }
}
