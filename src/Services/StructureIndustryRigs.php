<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\Schema;
use IndustryManager\Helpers\RigAttributes;
use Seat\Eveapi\Models\Corporation\CorporationStructure;

/**
 * The industry rig bonuses of a structure.
 *
 * Read through SeAT's corporation assets (`CorporationStructure::rig_slots`),
 * the same source Structure Manager uses for doctrine compliance and the same
 * shape Mining Manager uses for its moon rigs. No ESI call, no new scope.
 *
 * Each fitted rig carries its own bonus magnitude (2593 TE / 2594 ME / 2595
 * cost — only one of the three is ever non-zero on a given type) and its own
 * security-band multiplier (2355 / 2356 / 2357). So the effective bonus is the
 * rig's value scaled by the multiplier for the structure's band, taken from the
 * rig rather than from a table.
 *
 * Always returns a usable array: a structure with no detectable rig, no asset
 * sync, or a SDE missing the attributes resolves to zero bonuses, which is the
 * same as an unrigged structure. A broken asset mirror never takes the page
 * down.
 */
final class StructureIndustryRigs
{
    /**
     * Resolved fits by structure id + band, for the lifetime of the request.
     *
     * @var array<string, array>
     */
    private static array $cache = [];

    /**
     * Bonuses for one structure.
     *
     * @return array{band:string, multiplier:float, rigs:array, me_bonus:float, te_bonus:float, cost_bonus:float, source:string}
     */
    public static function forStructure(?int $structureId, ?float $security = null): array
    {
        $band = RigAttributes::securityBand($security);

        if (!$structureId) {
            return self::base($band);
        }

        $key = $structureId . ':' . $band;

        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = self::resolve($structureId, $band);
        }

        return self::$cache[$key];
    }

    /**
     * An unrigged fit: every bonus is 0, so the calculator's modifiers stay 1.0.
     *
     * @return array
     */
    public static function base(string $band): array
    {
        return [
            'band' => $band,
            'multiplier' => RigAttributes::fallbackMultiplier($band),
            'rigs' => [],
            'me_bonus' => 0.0,
            'te_bonus' => 0.0,
            'cost_bonus' => 0.0,
            'source' => 'base',
        ];
    }

    /**
     * Material modifier for a structure: (1 - effective ME bonus / 100).
     * Multiply the ME modifier by this; skills never affect quantities.
     */
    public static function materialModifier(array $fit): float
    {
        return 1.0 - max(0.0, $fit['me_bonus']) / 100.0;
    }

    /**
     * Time modifier for a structure: (1 - effective TE bonus / 100).
     */
    public static function timeModifier(array $fit): float
    {
        return 1.0 - max(0.0, $fit['te_bonus']) / 100.0;
    }

    /**
     * Cost modifier for a structure: (1 - effective job cost bonus / 100).
     */
    public static function costModifier(array $fit): float
    {
        return 1.0 - max(0.0, $fit['cost_bonus']) / 100.0;
    }

    /**
     * @return array
     */
    private static function resolve(int $structureId, string $band): array
    {
        try {
            if (!Schema::hasTable('corporation_assets') || !Schema::hasTable('dgmTypeAttributes')) {
                return self::base($band);
            }

            $structure = CorporationStructure::with([
                'items' => function ($query) {
                    $query->where('location_flag', 'like', 'RigSlot%')
                        ->with('type.dogma_attributes');
                },
            ])->find($structureId);

            if (!$structure) {
                return self::base($band);
            }

            $fallback = RigAttributes::fallbackMultiplier($band);
            $multiplierAttribute = RigAttributes::multiplierAttribute($band);

            $rigs = [];
            $best = ['me' => 0.0, 'te' => 0.0, 'cost' => 0.0];

            foreach ($structure->rig_slots as $item) {
                $attributes = optional($item->type)->dogma_attributes;

                if (!$attributes) {
                    continue;
                }

                // A rig only carries its own bonus; the other two read 0.
                $bonus = null;
                $raw = 0.0;

                foreach (RigAttributes::BONUS_LABELS as $attributeId => $label) {
                    $value = self::attribute($attributes, $attributeId);

                    if (abs($value) > 0.0) {
                        $bonus = $label;
                        $raw = $value;
                        break;
                    }
                }

                if ($bonus === null) {
                    continue;
                }

                $multiplier = $multiplierAttribute
                    ? (self::attribute($attributes, $multiplierAttribute) ?: $fallback)
                    : $fallback;

                $effective = round(abs($raw) * $multiplier, 2);

                $rigs[] = [
                    'type_id' => (int) $item->type_id,
                    'name' => optional($item->type)->typeName ?? ('Rig #' . $item->type_id),
                    'bonus' => $bonus,
                    'raw' => round($raw, 2),
                    'multiplier' => round($multiplier, 2),
                    'effective' => $effective,
                ];

                $best[$bonus] = max($best[$bonus], $effective);
            }

            return [
                'band' => $band,
                'multiplier' => $fallback,
                'rigs' => $rigs,
                'me_bonus' => $best['me'],
                'te_bonus' => $best['te'],
                'cost_bonus' => $best['cost'],
                'source' => $rigs ? 'fitted' : 'base',
            ];
        } catch (\Throwable $e) {
            // A broken asset mirror or a missing SDE table must never take the
            // structure or calculator pages down; fall back and carry on.
            return self::base($band);
        }
    }

    /**
     * One dogma attribute's value from a rig's attribute collection.
     *
     * @param \Illuminate\Support\Collection $attributes
     */
    private static function attribute($attributes, int $attributeId): float
    {
        $row = $attributes->firstWhere('attributeID', $attributeId);

        if (!$row) {
            return 0.0;
        }

        return $row->valueFloat !== null ? (float) $row->valueFloat : (float) $row->valueInt;
    }
}
