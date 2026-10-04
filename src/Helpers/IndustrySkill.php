<?php

namespace IndustryManager\Helpers;

/**
 * IndustrySkill — type IDs of the skills that affect industry time, plus the
 * per-level multipliers used to compute job duration from a blueprint's base
 * time.
 *
 * IMPORTANT: skills affect TIME and ELIGIBILITY only. They do NOT affect
 * material quantities (ME). Material reduction comes from blueprint ME +
 * structure role bonus + rig bonus only. Keep that separation strict so the
 * calculator never double-counts.
 *
 * Type IDs are stable CCP constants (the skill's typeID in invTypes).
 */
class IndustrySkill
{
    /** Industry — 4% manufacturing time reduction per level. */
    public const INDUSTRY = 3380;
    public const INDUSTRY_TIME_PER_LEVEL = 0.04;

    /** Advanced Industry — 3% time reduction per level on ALL job types. */
    public const ADVANCED_INDUSTRY = 3388;
    public const ADVANCED_INDUSTRY_TIME_PER_LEVEL = 0.03;

    /** Research — 5% TE-research time reduction per level. */
    public const RESEARCH = 3403;

    /** Metallurgy — 5% ME-research time reduction per level. */
    public const METALLURGY = 3409;

    /** Science — 5% copying time reduction per level. */
    public const SCIENCE = 3402;

    /** Reactions — 4% reaction time reduction per level. */
    public const REACTIONS = 45746;

    /** Laboratory Operation / Advanced Laboratory Operation — parallel job slots. */
    public const LABORATORY_OPERATION = 3406;
    public const ADVANCED_LABORATORY_OPERATION = 24624;

    /** Mass Production / Advanced Mass Production — parallel manufacturing slots. */
    public const MASS_PRODUCTION = 3387;
    public const ADVANCED_MASS_PRODUCTION = 24625;

    /** Per-level time reductions, as published on the skills themselves. */
    public const TIME_PER_LEVEL = [
        self::INDUSTRY => 0.04,
        self::ADVANCED_INDUSTRY => 0.03,
        self::RESEARCH => 0.05,
        self::METALLURGY => 0.05,
        self::SCIENCE => 0.05,
        self::REACTIONS => 0.04,
    ];

    /**
     * Which skills shorten which activity.
     *
     * Taken from the skills' dogma effects: each one writes a single character
     * time attribute (219 manufacturing, 385 TE research, 398 ME research, 387
     * copying, 1959 invention, 2662 reaction time), and Advanced Industry is the
     * only skill that writes several of them — manufacturing, both research
     * types, copying and invention, but not reactions.
     */
    public const ACTIVITY_SKILLS = [
        IndustryActivity::MANUFACTURING => [self::INDUSTRY, self::ADVANCED_INDUSTRY],
        IndustryActivity::RESEARCH_TE => [self::RESEARCH, self::ADVANCED_INDUSTRY],
        IndustryActivity::RESEARCH_ME => [self::METALLURGY, self::ADVANCED_INDUSTRY],
        IndustryActivity::COPYING => [self::SCIENCE, self::ADVANCED_INDUSTRY],
        IndustryActivity::INVENTION => [self::ADVANCED_INDUSTRY],
        IndustryActivity::REACTIONS => [self::REACTIONS],
    ];

    /**
     * Time multiplier for one activity from a character's skill levels.
     * Returns a factor in (0, 1]; multiply the activity's base time by it.
     *
     * @param  array<int,int>  $levels  skill typeID => trained level
     */
    public static function timeMultiplier(int $activityId, array $levels): float
    {
        $multiplier = 1.0;

        foreach (self::ACTIVITY_SKILLS[$activityId] ?? [] as $skillId) {
            $level = max(0, min(5, (int) ($levels[$skillId] ?? 0)));
            $multiplier *= 1.0 - (self::TIME_PER_LEVEL[$skillId] ?? 0.0) * $level;
        }

        return $multiplier;
    }

    /**
     * Compute the manufacturing time multiplier from skill levels.
     * Returns a factor in (0, 1]; multiply base time by it.
     *
     * time = baseTime * (1 - 0.04*Industry) * (1 - 0.03*AdvancedIndustry)
     * (TE and structure/rig time bonuses are applied separately by the caller.)
     *
     * This is the manufacturing case of timeMultiplier(); the other activities
     * read different skills.
     *
     * @param  int  $industryLevel  0-5
     * @param  int  $advancedIndustryLevel  0-5
     */
    public static function manufacturingTimeMultiplier(int $industryLevel, int $advancedIndustryLevel): float
    {
        $industryLevel = max(0, min(5, $industryLevel));
        $advancedIndustryLevel = max(0, min(5, $advancedIndustryLevel));

        return (1 - self::INDUSTRY_TIME_PER_LEVEL * $industryLevel)
            * (1 - self::ADVANCED_INDUSTRY_TIME_PER_LEVEL * $advancedIndustryLevel);
    }
}
