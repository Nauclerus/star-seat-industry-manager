<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Services\ProductionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The EVE material formula, exercised directly.
 *
 * required = max(runs, ceil(round(baseQuantity * runs * modifier, 2)))
 * modifier = (1 - ME/100) * structureModifier * rigModifier
 */
class ProductionCalculatorTest extends TestCase
{
    private ProductionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ProductionCalculator();
    }

    public function test_no_me_no_modifiers_is_base_times_runs(): void
    {
        $this->assertSame(100, $this->calc->adjustedQuantity(100, 1, 0.0));
        $this->assertSame(1000, $this->calc->adjustedQuantity(100, 10, 0.0));
    }

    public function test_me_reduces_quantity(): void
    {
        // 10% ME on 100 base, one run => 90.
        $this->assertSame(90, $this->calc->adjustedQuantity(100, 1, 0.10));
    }

    public function test_rig_modifier_compounds_with_me(): void
    {
        // 10% ME and a 2% rig bonus: 100 * (1 - 0.10) * (1 - 0.02) = 88.2 => 89.
        $this->assertSame(89, $this->calc->adjustedQuantity(100, 1, 0.10, 1.0, 0.98));
    }

    public function test_security_scaled_rig_bonus(): void
    {
        // The null-sec case from the live SDE: 2% base * 2.1 multiplier = 4.2%.
        // 100 * (1 - 0.042) = 95.8 => 96.
        $this->assertSame(96, $this->calc->adjustedQuantity(100, 1, 0.0, 1.0, 0.958));
    }

    public function test_round_to_two_decimals_before_ceiling(): void
    {
        // 100 * 0.895 = 89.5 => exactly 90 (no float drift to 91).
        $this->assertSame(90, $this->calc->adjustedQuantity(100, 1, 0.105));
    }

    public function test_at_least_one_unit_per_run(): void
    {
        // A tiny base with a large ME cannot drop below the run count.
        $this->assertSame(5, $this->calc->adjustedQuantity(1, 5, 0.99));
    }

    public function test_zero_runs_is_treated_as_one(): void
    {
        $this->assertSame(100, $this->calc->adjustedQuantity(100, 0, 0.0));
    }

    public function test_time_efficiency_is_two_percent_a_level_to_ten_levels(): void
    {
        $this->assertSame(1.0, ProductionCalculator::timeEfficiencyFactor(IndustryActivity::MANUFACTURING, 0));
        $this->assertSame(0.98, round(ProductionCalculator::timeEfficiencyFactor(IndustryActivity::MANUFACTURING, 1), 6));
        $this->assertSame(0.8, round(ProductionCalculator::timeEfficiencyFactor(IndustryActivity::MANUFACTURING, 10), 6));

        // The ten levels a researched copy reaches is the floor.
        $this->assertSame(0.8, round(ProductionCalculator::timeEfficiencyFactor(IndustryActivity::MANUFACTURING, 25), 6));
    }

    public function test_time_efficiency_only_touches_manufacturing(): void
    {
        // A copy is 80% of the build time in the archive itself, a research job
        // carries its rank multiplier there, and reaction blueprints have no ME or
        // TE levels at all — so TE changes none of those durations.
        $others = [
            IndustryActivity::COPYING,
            IndustryActivity::RESEARCH_ME,
            IndustryActivity::RESEARCH_TE,
            IndustryActivity::INVENTION,
            IndustryActivity::REACTIONS,
        ];

        foreach ($others as $activityId) {
            $this->assertSame(1.0, ProductionCalculator::timeEfficiencyFactor($activityId, 10));
        }
    }
}
