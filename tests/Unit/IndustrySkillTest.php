<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustrySkill;
use PHPUnit\Framework\TestCase;

/**
 * Skills affect duration and eligibility only — never material quantities.
 */
class IndustrySkillTest extends TestCase
{
    public function test_no_skills_means_no_reduction(): void
    {
        $this->assertSame(1.0, IndustrySkill::manufacturingTimeMultiplier(0, 0));
    }

    public function test_industry_four_percent_per_level(): void
    {
        $this->assertSame(0.8, round(IndustrySkill::manufacturingTimeMultiplier(5, 0), 4));
    }

    public function test_advanced_industry_three_percent_per_level(): void
    {
        $this->assertSame(0.85, round(IndustrySkill::manufacturingTimeMultiplier(0, 5), 4));
    }

    public function test_multipliers_compound(): void
    {
        // (1 - 0.04*5) * (1 - 0.03*5) = 0.8 * 0.85 = 0.68
        $this->assertSame(0.68, round(IndustrySkill::manufacturingTimeMultiplier(5, 5), 4));
    }

    public function test_levels_are_clamped(): void
    {
        $this->assertSame(
            IndustrySkill::manufacturingTimeMultiplier(5, 5),
            IndustrySkill::manufacturingTimeMultiplier(9, 9)
        );
    }
}
