<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Tests\TestCase;

/**
 * The presence guard reads "not loaded" for an absent OR empty table, so every
 * recipe-powered page degrades to a notice instead of a 500.
 */
class IndustryDataTest extends TestCase
{
    public function test_installed_is_false_when_tables_are_empty(): void
    {
        IndustryData::flush();

        $this->assertFalse(IndustryData::isInstalled());
        $this->assertFalse(IndustryData::isPiInstalled());
    }

    public function test_installed_is_true_once_rows_exist(): void
    {
        DB::table(IndustryData::TABLE_MATERIALS)->insert([
            'typeID' => 681,
            'activityID' => 1,
            'materialTypeID' => 38,
            'quantity' => 86,
        ]);

        IndustryData::flush();

        $this->assertTrue(IndustryData::isInstalled());
    }

    public function test_recipe_version_does_not_throw_without_seat_settings(): void
    {
        $this->assertNotSame('', IndustryData::recipeVersion());
    }
}
