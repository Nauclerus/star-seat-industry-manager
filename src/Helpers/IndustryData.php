<?php

namespace IndustryManager\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IndustryData — canonical table registry and runtime guard for the recipe data
 * this plugin consumes.
 *
 * These tables are plugin-owned. Nothing outside the plugin creates or writes
 * them; the plugin's own importer fills them from either source:
 *
 *   1. CCP's official JSONL SDE (blueprints.jsonl + planetSchematics.jsonl),
 *      chosen when SeAT core has the recipe seeders.
 *   2. Fuzzwork's per-table gzip dumps, the fallback for installs where core
 *      does not — which is stock SeAT v5 today.
 *
 * Column names stay canonical (CamelCase as CCP and Fuzzwork publish them) so
 * the recipe code reads naturally, but the tables belong to the plugin and are
 * dropped by its own migration.
 *
 * isInstalled()/isPiInstalled() check for actual ROWS, not just table existence,
 * so an absent table or a created-but-not-imported table both read as "not
 * loaded" and every recipe-powered page degrades to a neutral notice rather than
 * a 500 or a misleading "no recipe" everywhere. Everything that reads SeAT's
 * live synced tables (blueprints, jobs, structures, planetary colonies) works
 * regardless.
 *
 * No ESI. Reads SeAT's live synced tables + the plugin's own recipe tables.
 */
class IndustryData
{
    /** Plugin-owned recipe tables. */
    public const TABLE_ACTIVITY = 'industry_manager_recipes';
    public const TABLE_MATERIALS = 'industry_manager_materials';
    public const TABLE_PRODUCTS = 'industry_manager_products';
    public const TABLE_SKILLS = 'industry_manager_skills';
    public const TABLE_PROBABILITIES = 'industry_manager_probabilities';

    /** Plugin-owned Planetary Industry schematics. */
    public const TABLE_PI_SCHEMATICS = 'industry_manager_pi_schematics';
    public const TABLE_PI_TYPEMAP = 'industry_manager_pi_types';

    /**
     * The manufacturing/invention/reaction set.
     */
    public const TABLES = [
        self::TABLE_ACTIVITY,
        self::TABLE_MATERIALS,
        self::TABLE_PRODUCTS,
        self::TABLE_SKILLS,
        self::TABLE_PROBABILITIES,
    ];

    /**
     * The Planetary Industry set, tracked separately so the PI pages can be
     * absent without affecting the core industry pages, and vice versa.
     */
    public const PI_TABLES = [
        self::TABLE_PI_SCHEMATICS,
        self::TABLE_PI_TYPEMAP,
    ];

    /**
     * Per-request memos so repeated isInstalled() calls don't re-hit the schema
     * inspector (which queries information_schema on MySQL).
     */
    private static ?bool $installedMemo = null;

    private static ?bool $piInstalledMemo = null;

    /**
     * Is the manufacturing recipe data present AND populated?
     */
    public static function isInstalled(): bool
    {
        if (self::$installedMemo !== null) {
            return self::$installedMemo;
        }

        try {
            self::$installedMemo = Schema::hasTable(self::TABLE_MATERIALS)
                && Schema::hasTable(self::TABLE_PRODUCTS)
                && DB::table(self::TABLE_MATERIALS)->exists();
        } catch (\Throwable $e) {
            self::$installedMemo = false;
        }

        return self::$installedMemo;
    }

    /**
     * Is the Planetary Industry schematic data present AND populated? The live
     * colony data (character_planet_*) is synced by SeAT core and read
     * separately — this only gates the schematic recipe lookups.
     */
    public static function isPiInstalled(): bool
    {
        if (self::$piInstalledMemo !== null) {
            return self::$piInstalledMemo;
        }

        try {
            self::$piInstalledMemo = Schema::hasTable(self::TABLE_PI_SCHEMATICS)
                && Schema::hasTable(self::TABLE_PI_TYPEMAP)
                && DB::table(self::TABLE_PI_TYPEMAP)->exists();
        } catch (\Throwable $e) {
            self::$piInstalledMemo = false;
        }

        return self::$piInstalledMemo;
    }

    /**
     * Fine-grained presence check for an individual table, memo-free.
     */
    public static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Reset the memo. Needed in tests, or right after an import within the same
     * process.
     */
    public static function flush(): void
    {
        self::$installedMemo = null;
        self::$piInstalledMemo = null;
    }

    /**
     * Cache-busting token for recipe caches. The importer stamps
     * `industry_manager_sde_version` on each run so every cached recipe is
     * invalidated on re-import; until then this falls back to the core SDE
     * version, then a constant.
     */
    public static function recipeVersion(): string
    {
        try {
            $v = setting('industry_manager_sde_version', true);
            if ($v) {
                return (string) $v;
            }

            $core = setting('installed_sde', true);

            return $core ? (string) $core : 'none';
        } catch (\Throwable $e) {
            return 'none';
        }
    }
}
