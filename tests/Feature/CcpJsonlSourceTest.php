<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\ProductionCalculator;
use IndustryManager\Services\RecipeSources\CcpJsonlSource;
use IndustryManager\Tests\TestCase;

/**
 * The CCP JSONL source end to end: stage the files the way SeAT extracts them,
 * import, and confirm the calculator can then resolve a real recipe.
 */
class CcpJsonlSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('sde/3569502');
        mkdir($dir, 0755, true);
        copy(__DIR__ . '/../fixtures/blueprints.min.jsonl', $dir . '/blueprints.jsonl');
        copy(__DIR__ . '/../fixtures/planetSchematics.min.jsonl', $dir . '/planetSchematics.jsonl');
    }

    public function test_imports_recipe_and_pi_rows(): void
    {
        $counts = (new CcpJsonlSource())->import();

        // 681 carries copying + manufacturing + research_material + research_time,
        // 685 carries invention => 5 activity rows.
        $this->assertSame(5, $counts[IndustryData::TABLE_ACTIVITY]);
        $this->assertSame(2, $counts[IndustryData::TABLE_MATERIALS]);
        $this->assertSame(2, $counts[IndustryData::TABLE_PRODUCTS]);
        $this->assertSame(2, $counts[IndustryData::TABLE_SKILLS]);
        $this->assertSame(1, $counts[IndustryData::TABLE_PROBABILITIES]);
        $this->assertSame(1, $counts[IndustryData::TABLE_PI_SCHEMATICS]);
        $this->assertSame(2, $counts[IndustryData::TABLE_PI_TYPEMAP]);
    }

    public function test_version_comes_from_the_build_directory(): void
    {
        $source = new CcpJsonlSource();
        $source->import();

        $this->assertSame('3569502', $source->version());
    }

    public function test_import_marks_the_data_as_installed(): void
    {
        IndustryData::flush();
        (new CcpJsonlSource())->import();
        IndustryData::flush();

        $this->assertTrue(IndustryData::isInstalled());
        $this->assertTrue(IndustryData::isPiInstalled());
    }

    public function test_calculator_resolves_an_imported_recipe(): void
    {
        DB::table('invTypes')->insert([
            ['typeID' => 681, 'typeName' => 'Frigate Blueprint', 'groupID' => 1, 'published' => 1],
            ['typeID' => 165, 'typeName' => 'Frigate', 'groupID' => 1, 'published' => 1],
            ['typeID' => 38, 'typeName' => 'Tritanium', 'groupID' => 2, 'published' => 1],
        ]);
        DB::table('invGroups')->insert([
            ['groupID' => 1, 'categoryID' => 7, 'groupName' => 'Frigate'],
            ['groupID' => 2, 'categoryID' => 4, 'groupName' => 'Mineral'],
        ]);
        DB::table('invCategories')->insert([
            ['categoryID' => 7, 'categoryName' => 'Ship'],
            ['categoryID' => 4, 'categoryName' => 'Material'],
        ]);

        (new CcpJsonlSource())->import();
        IndustryData::flush();

        $recipe = (new ProductionCalculator())->recipe(681, IndustryActivity::MANUFACTURING);

        $this->assertNotNull($recipe);
        $this->assertSame(165, $recipe['product_type_id']);
        $this->assertSame(600, $recipe['time']);
        $this->assertSame(38, $recipe['materials'][0]['type_id']);
        $this->assertSame(86, $recipe['materials'][0]['base_quantity']);
    }
}
