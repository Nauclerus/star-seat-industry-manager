<?php

namespace IndustryManager\Services\RecipeSources;

/**
 * A source of industry + planetary recipe data.
 *
 * Both implementations write ONLY to the plugin's own `industry_manager_*`
 * tables. Neither creates, alters or writes anything in the core SDE namespace,
 * so the plugin stays removable.
 *
 * The two exist because SeAT core does not currently cover the recipe files:
 *
 *   CcpJsonlSource  — CCP's official JSONL SDE. Authoritative and current.
 *   FuzzworkSource  — Fuzzwork's per-table gzip dumps, which is what SeAT core
 *                     actually imports today. Complete for the recipe tables,
 *                     but it lags patches.
 *
 * SourceResolver picks between them, so a stock SeAT install works now and
 * switches to CCP automatically once core gains the recipe seeders.
 */
interface RecipeSource
{
    /**
     * Short identifier for the UI and the Settings page: 'ccp-jsonl' | 'fuzzwork'.
     */
    public function name(): string;

    /**
     * Human label for the Settings/Diagnostic pages.
     */
    public function label(): string;

    /**
     * Build number or dump stamp, used as the recipe cache key.
     */
    public function version(): string;

    /**
     * Import into the plugin's tables.
     *
     * @return array<string,int>  rows imported per plugin table
     */
    public function import(): array;
}
