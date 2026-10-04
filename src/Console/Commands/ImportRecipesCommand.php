<?php

namespace IndustryManager\Console\Commands;

use Illuminate\Console\Command;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\RecipeSources\RecipeSourceResolver;

/**
 * Imports the industry + planetary recipe data the plugin needs.
 *
 * Source is chosen automatically: CCP's official JSONL SDE when SeAT core can
 * provide it (the recipe seeders are present), otherwise Fuzzwork's per-table
 * gzip dumps. Pass --source to override.
 *
 * Re-runnable: each plugin table is cleared and refilled, so this refreshes the
 * recipe data after an EVE patch. Writes only to `industry_manager_*`; nothing in
 * the core SDE namespace is touched.
 */
class ImportRecipesCommand extends Command
{
    protected $signature = 'industry-manager:import-recipes
        {--source= : ccp-jsonl or fuzzwork (default: auto)}
        {--keep-files : keep downloaded/extracted files}';

    protected $description = "Import EVE industry & planetary recipes into the plugin's own tables (auto-selects CCP JSONL or Fuzzwork).";

    public function handle(): int
    {
        $name = $this->option('source') ?: RecipeSourceResolver::preferredName();

        if (! in_array($name, ['ccp-jsonl', 'fuzzwork'], true)) {
            $this->error('Unknown source: ' . $name);
            $this->line('Valid sources: ccp-jsonl, fuzzwork');

            return self::FAILURE;
        }

        $source = RecipeSourceResolver::make($name);

        $this->info('Importing recipe data from: ' . $source->label());
        $this->line('');

        try {
            $counts = $source->import();
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($counts as $table => $rows) {
            $this->line(sprintf('  %-34s %6d rows', $table, (int) $rows));
        }

        IndustryData::flush();

        // Stamp a fresh recipe version so cached recipes (keyed by
        // IndustryData::recipeVersion) are invalidated on this re-import.
        try {
            \Seat\Services\Settings\Seat::set('industry_manager_sde_version', $source->version() ?: (string) time());
        } catch (\Throwable $e) {
            // non-fatal; caching falls back to the core SDE version
        }

        $this->line('');
        $this->info('Recipe data imported from ' . $source->name() . ' (' . $source->version() . ').');

        return self::SUCCESS;
    }
}
