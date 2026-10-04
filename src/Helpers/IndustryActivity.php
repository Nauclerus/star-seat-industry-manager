<?php

namespace IndustryManager\Helpers;

/**
 * IndustryActivity — EVE industry activity IDs + display metadata.
 *
 * These IDs are the `activityID` column shared across the industryActivity*
 * SDE tables and the `activity_id` column on character/corporation_industry_jobs.
 * They are stable CCP constants.
 */
class IndustryActivity
{
    public const MANUFACTURING = 1;
    public const RESEARCH_TE = 3;   // Researching Time Efficiency
    public const RESEARCH_ME = 4;   // Researching Material Efficiency
    public const COPYING = 5;
    public const INVENTION = 8;
    public const REACTIONS = 11;    // Modern (Athanor/Tatara) reactions

    /**
     * Legacy / defunct activity IDs we recognise for labelling old jobs but
     * do not actively calculate for:
     *   2  = Researching Technology (removed)
     *   6  = Duplicating (removed)
     *   7  = Reverse Engineering (removed with T3 rework)
     *   9  = Reactions (old POS simple reactions; superseded by 11)
     */
    public const LEGACY = [2, 6, 7, 9];

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
            9 => 'Reactions (legacy POS)',
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
