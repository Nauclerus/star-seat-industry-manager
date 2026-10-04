<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Tests\TestCase;

/**
 * The plugin-owned tables are created by the plugin's own migrations and dropped
 * by their down(), so removing the plugin leaves nothing behind.
 */
class MigrationTest extends TestCase
{
    public function test_recipe_tables_are_created(): void
    {
        foreach (IndustryData::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table . ' should exist');
        }

        foreach (IndustryData::PI_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table . ' should exist');
        }
    }

    public function test_pi_project_tables_use_the_plugin_prefix(): void
    {
        $this->assertTrue(Schema::hasTable('industry_manager_pi_projects'));
        $this->assertTrue(Schema::hasTable('industry_manager_pi_project_objectives'));
        $this->assertTrue(Schema::hasTable('industry_manager_pi_project_planets'));
    }

    public function test_recipe_migration_down_drops_its_tables(): void
    {
        $migration = require __DIR__ . '/../../src/Database/migrations/2026_10_04_000001_create_industry_manager_recipe_tables.php';
        $migration->down();

        foreach (array_merge(IndustryData::TABLES, IndustryData::PI_TABLES) as $table) {
            $this->assertFalse(Schema::hasTable($table), $table . ' should be dropped');
        }
    }

    public function test_no_core_sde_table_is_owned_by_the_plugin(): void
    {
        // Canonical SDE names must never be created or dropped by the plugin.
        foreach (['industryActivity', 'industryActivityMaterials', 'planetSchematics', 'planetSchematicsTypeMap'] as $coreName) {
            $this->assertFalse(Schema::hasTable($coreName), $coreName . ' must not exist');
        }
    }
}
