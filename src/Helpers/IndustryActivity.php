<?php

namespace IndustryManager\Helpers;

/**
 * IndustryActivity — EVE industry activity IDs + display metadata.
 *
 * These are CCP's own activity IDs: the `activityID` of the recipe tables, the
 * `activityID` of industryActivities.jsonl / ramActivities, and the `activity_id`
 * SeAT stores on character_industry_jobs and corporation_industry_jobs. They come
 * straight from live job rows, so the same number means the same activity on both
 * sides of every join this plugin does.
 *
 * Verified against SDE build 3569502 and the live job tables on 2026-10-04:
 * reaction jobs are recorded under 9, and 9 is what industryActivities.jsonl
 * calls "Reactions". The Upwell rework kept the POS-era activity rather than
 * introducing a new one, which is why the reaction product groups and the
 * reactor service modules all hang off 9.
 */
class IndustryActivity
{
    public const MANUFACTURING = 1;
    public const RESEARCH_TE = 3;   // Researching Time Efficiency
    public const RESEARCH_ME = 4;   // Researching Material Efficiency
    public const COPYING = 5;
    public const INVENTION = 8;
    public const REACTIONS = 9;     // Upwell reactions (Athanor, Tatara, industry complexes)

    /**
     * Legacy / defunct activity IDs we recognise for labelling old jobs but
     * do not actively calculate for:
     *   2  = Researching Technology (removed)
     *   6  = Duplicating (removed)
     *   7  = Reverse Engineering (removed with T3 rework)
     *   11 = an ID that appears in older third-party dumps for reactions; CCP
     *        has never used it, and no live job or recipe row carries it.
     */
    public const LEGACY = [2, 6, 7, 11];

    /**
     * Activities this plugin actively calculates production for in v1.
     */
    public const CALCULABLE = [
        self::MANUFACTURING,
        self::INVENTION,
        self::REACTIONS,
    ];

    /**
     * Human label for an activity ID. Falls back to a generic label so an
     * unexpected/new ID never breaks the UI.
     */
    public static function name(int $activityId): string
    {
        return [
            self::MANUFACTURING => 'Manufacturing',
            self::RESEARCH_TE => 'Time Efficiency Research',
            self::RESEARCH_ME => 'Material Efficiency Research',
            self::COPYING => 'Copying',
            self::INVENTION => 'Invention',
            self::REACTIONS => 'Reactions',
            2 => 'Technology Research (legacy)',
            6 => 'Duplicating (legacy)',
            7 => 'Reverse Engineering (legacy)',
            11 => 'Reactions (legacy dump)',
        ][$activityId] ?? ('Activity #' . $activityId);
    }

    /**
     * FontAwesome icon per activity, for consistent iconography across views.
     */
    public static function icon(int $activityId): string
    {
        return [
            self::MANUFACTURING => 'fas fa-industry',
            self::RESEARCH_TE => 'fas fa-clock',
            self::RESEARCH_ME => 'fas fa-cubes',
            self::COPYING => 'fas fa-copy',
            self::INVENTION => 'fas fa-flask',
            self::REACTIONS => 'fas fa-atom',
        ][$activityId] ?? 'fas fa-cog';
    }
}
