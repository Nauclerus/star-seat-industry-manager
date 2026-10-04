<?php

namespace IndustryManager\Helpers;

/**
 * PiTier — classifies Planetary Industry materials into tiers P0..P4 by their
 * market group. This is factual EVE data (the PI material market-group IDs),
 * mirrored from the standard mapping; not derived from any third-party plugin's
 * code.
 *
 *   1333 -> P0 Raw (extracted)
 *   1334 -> P1 Processed
 *   1335 -> P2 Refined
 *   1336 -> P3 Specialized
 *   1337 -> P4 Advanced
 */
class PiTier
{
    public const MARKET_GROUP_TIER = [
        1333 => 0,
        1334 => 1,
        1335 => 2,
        1336 => 3,
        1337 => 4,
    ];

    public static function tierForMarketGroup(?int $marketGroupId): ?int
    {
        if ($marketGroupId === null) {
            return null;
        }

        return self::MARKET_GROUP_TIER[$marketGroupId] ?? null;
    }

    public static function shortLabel(?int $tier): string
    {
        return $tier === null ? '—' : ('P' . $tier);
    }

    public static function name(?int $tier): string
    {
        return [
            0 => 'Raw',
            1 => 'Processed',
            2 => 'Refined',
            3 => 'Specialized',
            4 => 'Advanced',
        ][$tier] ?? 'PI';
    }
}
