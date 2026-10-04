<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustryActivity;
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

    public function test_each_activity_reads_its_own_skills(): void
    {
        // A character with all of them at V.
        $levels = [
            IndustrySkill::INDUSTRY => 5,
            IndustrySkill::ADVANCED_INDUSTRY => 5,
            IndustrySkill::SCIENCE => 5,
            IndustrySkill::RESEARCH => 5,
            IndustrySkill::METALLURGY => 5,
            IndustrySkill::REACTIONS => 5,
        ];

        // Manufacturing: Industry 4% and Advanced Industry 3%.
        $this->assertSame(0.68, round(IndustrySkill::timeMultiplier(IndustryActivity::MANUFACTURING, $levels), 4));

        // Copying, TE research and ME research: their own 5% skill plus Advanced
        // Industry, so 0.75 * 0.85.
        foreach ([IndustryActivity::COPYING, IndustryActivity::RESEARCH_TE, IndustryActivity::RESEARCH_ME] as $activityId) {
            $this->assertSame(0.6375, round(IndustrySkill::timeMultiplier($activityId, $levels), 4));
        }

        // Invention: Advanced Industry alone.
        $this->assertSame(0.85, round(IndustrySkill::timeMultiplier(IndustryActivity::INVENTION, $levels), 4));

        // Reactions: the Reactions skill alone — Advanced Industry does not reach it.
        $this->assertSame(0.8, round(IndustrySkill::timeMultiplier(IndustryActivity::REACTIONS, $levels), 4));
    }

    public function test_a_skill_only_counts_for_the_activities_it_writes(): void
    {
        $levels = [IndustrySkill::INDUSTRY => 5];

        $this->assertSame(0.8, round(IndustrySkill::timeMultiplier(IndustryActivity::MANUFACTURING, $levels), 4));
        $this->assertSame(1.0, IndustrySkill::timeMultiplier(IndustryActivity::COPYING, $levels));
        $this->assertSame(1.0, IndustrySkill::timeMultiplier(IndustryActivity::REACTIONS, $levels));
    }

    public function test_unknown_activity_has_no_skill_reduction(): void
    {
        $this->assertSame(1.0, IndustrySkill::timeMultiplier(0, [IndustrySkill::INDUSTRY => 5]));
    }
}
