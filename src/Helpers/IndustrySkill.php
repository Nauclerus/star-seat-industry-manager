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

    /** Research — 5% ME-research time reduction per level. */
    public const RESEARCH = 3403;

    /** Metallurgy — 5% ME-research time reduction per level (research activity). */
    public const METALLURGY = 3409;

    /** Science — 5% TE-research + copy time reduction per level. */
    public const SCIENCE = 3402;

    /** Laboratory Operation / Advanced Laboratory Operation — parallel job slots. */
    public const LABORATORY_OPERATION = 3406;
    public const ADVANCED_LABORATORY_OPERATION = 24624;

    /** Mass Production / Advanced Mass Production — parallel manufacturing slots. */
    public const MASS_PRODUCTION = 3387;
    public const ADVANCED_MASS_PRODUCTION = 24625;

    /**
     * Compute the manufacturing time multiplier from skill levels.
     * Returns a factor in (0, 1]; multiply base time by it.
     *
     * time = baseTime * (1 - 0.04*Industry) * (1 - 0.03*AdvancedIndustry)
     * (TE and structure/rig time bonuses are applied separately by the caller.)
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
