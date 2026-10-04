<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\ProductionCalculator;
use IndustryManager\Tests\TestCase;

/**
 * The tree against the rules the SDE and the in-game industry window follow:
 * blueprint levels change a manufacturing run, and nothing else.
 *
 * Durations themselves are published per activity, so the tree applies no
 * duration math beyond the blueprint's own levels.
 */
class ProductionTreeTest extends TestCase
{
    private const BP = 681;
    private const PRODUCT = 165;
    private const MATERIAL = 38;

    private ProductionCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calc = new ProductionCalculator();

        // Blueprint 681 as CCP publishes it: 600 s to build, 480 s to copy,
        // 86 units of the material, and its research durations.
        DB::table(IndustryData::TABLE_ACTIVITY)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::MANUFACTURING,
            'time' => 600,
        ]);
        DB::table(IndustryData::TABLE_ACTIVITY)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::COPYING,
            'time' => 480,
        ]);

        DB::table(IndustryData::TABLE_PRODUCTS)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::MANUFACTURING,
            'productTypeID' => self::PRODUCT,
            'quantity' => 1,
        ]);

        DB::table(IndustryData::TABLE_MATERIALS)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::MANUFACTURING,
            'materialTypeID' => self::MATERIAL,
            'quantity' => 86,
        ]);
        DB::table(IndustryData::TABLE_MATERIALS)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::COPYING,
            'materialTypeID' => self::MATERIAL,
            'quantity' => 86,
        ]);

        DB::table('invTypes')->insert([
            'typeID' => self::BP,
            'typeName' => 'Test Blueprint',
            'groupID' => 708,
        ]);
        DB::table('invTypes')->insert([
            'typeID' => self::PRODUCT,
            'typeName' => 'Test Product',
            'groupID' => 708,
        ]);
        DB::table('invTypes')->insert([
            'typeID' => self::MATERIAL,
            'typeName' => 'Test Material',
            'groupID' => 25,
        ]);

        IndustryData::flush();
    }

    public function test_time_efficiency_shortens_a_manufacturing_run(): void
    {
        $node = $this->calc->tree(self::BP, ['activity_id' => IndustryActivity::MANUFACTURING, 'te' => 10])['root'];

        // 600 s * (1 - 0.02 * 10) = 480 s.
        $this->assertSame(600, $node['time']);
        $this->assertSame(480, $node['adjusted_time']);
    }

    public function test_ten_levels_of_time_efficiency_is_the_floor(): void
    {
        $node = $this->calc->tree(self::BP, ['activity_id' => IndustryActivity::MANUFACTURING, 'te' => 40])['root'];

        $this->assertSame(480, $node['adjusted_time']);
    }

    public function test_a_copying_run_keeps_the_duration_the_sde_publishes(): void
    {
        $node = $this->calc->tree(self::BP, ['activity_id' => IndustryActivity::COPYING, 'te' => 10])['root'];

        // 480 s is already 80% of the build time; TE does not touch it.
        $this->assertSame(480, $node['adjusted_time']);
    }

    public function test_material_efficiency_does_not_reduce_a_copying_job(): void
    {
        $node = $this->calc->tree(self::BP, [
            'activity_id' => IndustryActivity::COPYING,
            'me' => 10,
        ])['root'];

        $this->assertSame(86, $node['materials'][0]['quantity']);
    }

    public function test_material_efficiency_reduces_a_manufacturing_run(): void
    {
        $node = $this->calc->tree(self::BP, [
            'activity_id' => IndustryActivity::MANUFACTURING,
            'me' => 10,
            'runs' => 2,
        ])['root'];

        // 86 * 2 * 0.90 = 154.8 => 155.
        $this->assertSame(155, $node['materials'][0]['quantity']);
    }
}
