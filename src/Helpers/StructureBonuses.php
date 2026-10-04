<?php

namespace IndustryManager\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * StructureBonuses — the bonus a structure grants to everything it runs, on top
 * of the rigs fitted in it.
 *
 * CCP publishes this as a mapping from structure typeID to the dogma attributes
 * that hold the multipliers (industryModifierSources.jsonl), and the values
 * themselves sit in the type's dogma attributes, which SeAT already syncs into
 * `dgmTypeAttributes`. So the numbers are read, not remembered:
 *
 *   2600  structure material multiplier   0.99 in every engineering complex
 *   2601  structure cost multiplier       0.97 Raitaru, 0.96 Azbel, 0.95 Sotiyo
 *   2602  structure time multiplier       0.85 Raitaru, 0.80 Azbel, 0.70 Sotiyo
 *   2721  reaction time multiplier        0.75 Tatara
 *
 * Which activity each attribute feeds is part of the mapping, not an assumption:
 * complexes feed material/cost/time to manufacturing but only cost/time to
 * copying, invention and research, and the Tatara's 2721 feeds reactions only.
 *
 * The absence of a row is the other half of the answer. Astrahus, Fortizar,
 * Keepstar and Athanor publish none of these attributes, so a citadel runs a job
 * at its base cost and time even when it is perfectly able to run it, and an
 * Athanor gets no reaction speed bonus while a Tatara gets 25%.
 */
class StructureBonuses
{
    /** Attribute ids, named for what CCP names them. */
    public const MATERIAL_ATTRIBUTE = 2600;
    public const COST_ATTRIBUTE = 2601;
    public const TIME_ATTRIBUTE = 2602;
    public const REACTION_TIME_ATTRIBUTE = 2721;

    /**
     * activityID => which attributes feed it.
     *
     * Straight from industryModifierSources.jsonl (build 3569502) for the
     * structures that publish any: the three engineering complexes and the
     * Tatara. Structures that are absent from that file simply have no entry to
     * read and land on 1.0.
     */
    public const ACTIVITY_ATTRIBUTES = [
        IndustryActivity::MANUFACTURING => [
            'material' => self::MATERIAL_ATTRIBUTE,
            'cost' => self::COST_ATTRIBUTE,
            'time' => self::TIME_ATTRIBUTE,
        ],
        IndustryActivity::COPYING => [
            'cost' => self::COST_ATTRIBUTE,
            'time' => self::TIME_ATTRIBUTE,
        ],
        IndustryActivity::INVENTION => [
            'cost' => self::COST_ATTRIBUTE,
            'time' => self::TIME_ATTRIBUTE,
        ],
        IndustryActivity::RESEARCH_ME => [
            'cost' => self::COST_ATTRIBUTE,
            'time' => self::TIME_ATTRIBUTE,
        ],
        IndustryActivity::RESEARCH_TE => [
            'cost' => self::COST_ATTRIBUTE,
            'time' => self::TIME_ATTRIBUTE,
        ],
        IndustryActivity::REACTIONS => [
            'time' => self::REACTION_TIME_ATTRIBUTE,
        ],
    ];

    /** @var array<int, array<int, float>>  typeID => attributeID => value */
    private static array $values = [];

    private static bool $readFailed = false;

    /**
     * Multipliers this structure type applies to an activity. Anything it does
     * not publish reads 1.0.
     *
     * @return array{material: float, cost: float, time: float}
     */
    public static function for(int $typeId, int $activityId): array
    {
        $attributes = self::ACTIVITY_ATTRIBUTES[$activityId] ?? [];

        $out = ['material' => 1.0, 'cost' => 1.0, 'time' => 1.0];

        foreach ($attributes as $kind => $attributeId) {
            $value = self::value($typeId, $attributeId);

            // A published multiplier of 0 would erase the job rather than reduce
            // it, so anything outside a sane range is treated as "no bonus".
            if ($value !== null && $value > 0.0 && $value <= 1.0) {
                $out[$kind] = $value;
            }
        }

        return $out;
    }

    /**
     * Does this structure type publish any industry bonus at all? Used to label a
     * structure honestly instead of implying a bonus it does not give.
     */
    public static function publishesAny(int $typeId): bool
    {
        foreach (self::ACTIVITY_ATTRIBUTES as $attributes) {
            foreach ($attributes as $attributeId) {
                if (self::value($typeId, $attributeId) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Read one attribute for one type from the table SeAT already syncs.
     */
    private static function value(int $typeId, int $attributeId): ?float
    {
        if (self::$readFailed) {
            return null;
        }

        if (! isset(self::$values[$typeId])) {
            try {
                $rows = DB::table('dgmTypeAttributes')
                    ->where('typeID', $typeId)
                    ->whereIn('attributeID', self::attributeIds())
                    ->get();

                $found = [];

                foreach ($rows as $row) {
                    $raw = $row->valueFloat ?? $row->valueInt ?? null;

                    if ($raw !== null) {
                        $found[(int) $row->attributeID] = (float) $raw;
                    }
                }

                self::$values[$typeId] = $found;
            } catch (\Throwable $e) {
                // No synced attributes on this install: everything reads as 1.0.
                self::$readFailed = true;
                self::$values = [];

                return null;
            }
        }

        return self::$values[$typeId][$attributeId] ?? null;
    }

    /**
     * @return array<int, int>
     */
    private static function attributeIds(): array
    {
        $ids = [];

        foreach (self::ACTIVITY_ATTRIBUTES as $attributes) {
            foreach ($attributes as $attributeId) {
                $ids[$attributeId] = $attributeId;
            }
        }

        return array_values($ids);
    }

    /**
     * Drop the memo. Tests, and right after an SDE update.
     */
    public static function flush(): void
    {
        self::$values = [];
        self::$readFailed = false;
    }
}
