<?php

namespace IndustryManager\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IndustryManager\Services\RecipeSources\RecipeSourceResolver;

/**
 * IndustryData — canonical table registry and runtime guard for the recipe data
 * this plugin consumes.
 *
 * These tables are plugin-owned. Nothing outside the plugin creates or writes
 * them; the plugin's own importer fills them:
 *
 *   1. CCP's official JSONL SDE (blueprints.jsonl + planetSchematics.jsonl),
 *      always the source.
 *   2. Fuzzwork's per-table gzip dumps, used only when CCP cannot deliver.
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

    /** Plugin-owned dogma effect definitions — where a rig's scope lives. */
    public const TABLE_EFFECTS = 'industry_manager_effects';

    /** Plugin-owned production plan: one row per run, with its assignment. */
    public const TABLE_RUNS = 'industry_manager_production_runs';

    /**
     * Plugin-owned structure capability map: service module -> assembly line ->
     * activity + accepted product groups.
     */
    public const TABLE_ASSEMBLY_LINES = 'industry_manager_assembly_lines';
    public const TABLE_INSTALLATIONS = 'industry_manager_installation_types';

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
     * Dogma effects, imported alongside the recipes by both sources. Tracked
     * separately because the rig scope degrades gracefully: without it the
     * calculator still works, it just cannot tell which fitted rig covers a
     * given job.
     */
    public const EFFECT_TABLES = [
        self::TABLE_EFFECTS,
    ];

    /**
     * The structure capability set, tracked separately because only CCP publishes
     * it: with the Fuzzwork fallback the tables stay empty and the calculator
     * falls back to the curated structure-type gate instead of the fitted-service
     * gate.
     */
    public const CAPABILITY_TABLES = [
        self::TABLE_ASSEMBLY_LINES,
        self::TABLE_INSTALLATIONS,
    ];

    /**
     * Per-request memos so repeated isInstalled() calls don't re-hit the schema
     * inspector (which queries information_schema on MySQL).
     */
    private static ?bool $installedMemo = null;

    private static ?bool $piInstalledMemo = null;

    private static ?bool $effectsInstalledMemo = null;

    private static ?bool $capabilityInstalledMemo = null;

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
     * Are the dogma effect definitions present AND populated? Without them the
     * rig scope cannot be resolved, so the calculator falls back to the fit-wide
     * best-per-attribute behaviour instead of the applicable-rig behaviour.
     */
    public static function isEffectsInstalled(): bool
    {
        if (self::$effectsInstalledMemo !== null) {
            return self::$effectsInstalledMemo;
        }

        try {
            self::$effectsInstalledMemo = Schema::hasTable(self::TABLE_EFFECTS)
                && DB::table(self::TABLE_EFFECTS)->exists();
        } catch (\Throwable $e) {
            self::$effectsInstalledMemo = false;
        }

        return self::$effectsInstalledMemo;
    }

    /**
     * Are the assembly-line/service-module tables present AND populated? Without
     * them the plugin cannot tell which activity a structure's fitted service
     * modules actually enable, and falls back to the curated structure-type gate.
     */
    public static function isCapabilityInstalled(): bool
    {
        if (self::$capabilityInstalledMemo !== null) {
            return self::$capabilityInstalledMemo;
        }

        try {
            self::$capabilityInstalledMemo = Schema::hasTable(self::TABLE_ASSEMBLY_LINES)
                && Schema::hasTable(self::TABLE_INSTALLATIONS)
                && DB::table(self::TABLE_INSTALLATIONS)->exists();
        } catch (\Throwable $e) {
            self::$capabilityInstalledMemo = false;
        }

        return self::$capabilityInstalledMemo;
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
        self::$effectsInstalledMemo = null;
        self::$capabilityInstalledMemo = null;
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

    /**
     * Which source the loaded recipe data actually came from, as stamped by the
     * importer. Falls back to the source a fresh import would use.
     */
    public static function recipeSource(): string
    {
        try {
            $s = setting('industry_manager_recipe_source', true);

            if ($s && in_array((string) $s, ['ccp-jsonl', 'fuzzwork'], true)) {
                return (string) $s;
            }
        } catch (\Throwable $e) {
            // settings unavailable; report what an import would pick
        }

        return RecipeSourceResolver::preferredName();
    }
}
