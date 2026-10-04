<?php

namespace IndustryManager\Helpers;

/**
 * AssemblyLines — what a fitted service module makes possible.
 *
 * CCP describes an industry activity from the structure side as an assembly line:
 * it belongs to one activity, and it lists the product groups and categories it
 * accepts. A line with neither list is not product-specific (a lab slot runs
 * copying or research for any blueprint).
 *
 * The lists are what turn remembered rules into checked ones. Line 175, "Structure
 * Basic Manufacturing", is the Standup Manufacturing Plant I: its groups cover
 * frigates through tactical destroyers and its categories exclude ships as a
 * category, so a Dreadnought (group 485) matches only lines 176/177 — the Capital
 * and Supercapital Shipyards. Nothing here is a guess about what a structure is
 * allowed to build.
 */
class AssemblyLines
{
    /**
     * Decode the JSON group/category columns into plain int arrays.
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        // The columns hold JSON arrays of ids. Anything else (an object, a scalar,
        // malformed text) is treated as "no list" rather than guessed at.
        if (! is_array($decoded) || array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }

    /**
     * Does this line accept a product of the given group/category?
     *
     * A line with no group and no category list accepts anything for its activity.
     * Otherwise the product matches when its group is listed, or when its category
     * is listed and the group is not contradicted — CCP lists groups for the
     * products it wants to single out and categories for the broad ones.
     */
    public static function accepts(array $line, ?int $groupId, ?int $categoryId): bool
    {
        $groups = $line['groupIDs'] ?? [];
        $categories = $line['categoryIDs'] ?? [];

        if (empty($groups) && empty($categories)) {
            return true;
        }

        if ($groupId !== null && in_array($groupId, $groups, true)) {
            return true;
        }

        if ($categoryId === null) {
            // Nothing more to match on. A group-specific line stays group-specific.
            return empty($groups);
        }

        return in_array($categoryId, $categories, true);
    }

    /**
     * The first of a structure's fitted lines that can run this activity for this
     * product, or null when none of them can.
     *
     * @param  array  $lines  Lines keyed by assemblyLineID, each with activityID,
     *                        name, groupIDs and categoryIDs already decoded.
     */
    public static function find(array $lines, int $activityId, ?int $groupId, ?int $categoryId): ?array
    {
        foreach ($lines as $line) {
            if ((int) ($line['activityID'] ?? 0) !== $activityId) {
                continue;
            }

            if (self::accepts($line, $groupId, $categoryId)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Activities a set of fitted lines covers at all, ignoring the product. Used
     * to describe a structure on the structures page.
     *
     * @return array<int, array>  activityID => the line that provides it
     */
    public static function activities(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            $activityId = (int) ($line['activityID'] ?? 0);

            if ($activityId && ! isset($found[$activityId])) {
                $found[$activityId] = $line;
            }
        }

        return $found;
    }
}
