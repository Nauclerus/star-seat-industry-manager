<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\RigAttributes;
use IndustryManager\Helpers\RigScope;
use Seat\Eveapi\Models\Corporation\CorporationStructure;

/**
 * The industry rig bonuses of a structure.
 *
 * Read through SeAT's corporation assets (`CorporationStructure::rig_slots`),
 * the same source Structure Manager uses for doctrine compliance and the same
 * shape Mining Manager uses for its moon rigs. No ESI call, no new scope.
 *
 * Each fitted rig carries its own bonus magnitude (2593 TE / 2594 ME / 2595 cost,
 * or 2653 for Thukker faction rigs) and its own security-band multiplier
 * (2355 / 2356 / 2357). So the effective bonus is the rig's value scaled by the
 * multiplier for the structure's band, taken from the rig rather than from a
 * table — which is what makes Thukker rigs come out right.
 *
 * Rigs do not stack: the fitting rule allows one rig per effect, and a rig only
 * covers the scopes its dogma effects write. So for a given job the resolver
 * picks the single rig whose effect writes that job's multiplier attribute,
 * rather than taking the best value across every fitted rig. A structure with a
 * small-ship ME rig and a large-ship ME rig gives different bonuses to different
 * blueprints, and that is what `bonusesFor()` reports.
 *
 * Always returns a usable array: a structure with no detectable rig, no asset
 * sync, or a SDE missing the attributes resolves to zero bonuses, which is the
 * same as an unrigged structure. A broken asset mirror never takes the page down.
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
     * Effect definitions per rig type, memoised per request.
     *
     * @var array<int, array>
     */
    private static array $effectMemo = [];

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
     * Effective bonuses for one specific job.
     *
     * When the job's scope is known, only the rig that covers it counts — which is
     * the exclusion rule, since only one rig can write a given multiplier
     * attribute. When the effect definitions are not imported, this falls back to
     * the fit-wide best per attribute and says so in `source`.
     *
     * @return array{me:float, te:float, cost:float, scope:string, source:string, rig:?array}
     */
    public static function bonusesFor(array $fit, int $activityId, string $scope): array
    {
        $targets = RigScope::multiplierAttributes($activityId, $scope);

        // Without effect definitions there is nothing to match against, so the
        // fit-wide best is the honest answer rather than zero.
        $hasEffectData = false;

        foreach ($fit['rigs'] as $rig) {
            if (!empty($rig['attributes'])) {
                $hasEffectData = true;
                break;
            }
        }

        if ($targets === null || !$hasEffectData) {
            return [
                'me' => $fit['me_bonus'],
                'te' => $fit['te_bonus'],
                'cost' => $fit['cost_bonus'],
                'scope' => $scope,
                'source' => $fit['source'],
                'rig' => null,
            ];
        }

        $best = ['me' => 0.0, 'te' => 0.0, 'cost' => 0.0];
        $matched = null;

        foreach ($fit['rigs'] as $rig) {
            $written = $rig['attributes'] ?? [];

            if (empty($written)) {
                continue;
            }

            // Only one rig can write a given multiplier attribute, so the match is
            // per attribute, not per rig. Time bonuses come from the second
            // attribute of the pair; material and cost from the first.
            foreach (['me', 'te', 'cost'] as $label) {
                $byLabel = $rig['effective_by_label'] ?? [];

                if (!isset($byLabel[$label])) {
                    continue;
                }

                $target = $label === 'te' ? $targets[1] : $targets[0];

                if (!in_array($target, $written, true)) {
                    continue;
                }

                $best[$label] = max($best[$label], $byLabel[$label]);

                if ($matched === null || $byLabel[$label] > $matched['effective']) {
                    $matched = $rig;
                }
            }
        }

        return [
            'me' => $best['me'],
            'te' => $best['te'],
            'cost' => $best['cost'],
            'scope' => $scope,
            'source' => $matched ? 'scope' : 'uncovered',
            'rig' => $matched,
        ];
    }

    /**
     * Material modifier for a structure: (1 - effective ME bonus / 100).
     * Multiply the ME modifier by this; skills never affect quantities.
     */
    public static function materialModifier(array $fit, int $activityId = 1, string $scope = ''): float
    {
        return 1.0 - max(0.0, self::jobBonus($fit, $activityId, $scope, 'me') / 100.0);
    }

    /**
     * Time modifier for a structure: (1 - effective TE bonus / 100).
     */
    public static function timeModifier(array $fit, int $activityId = 1, string $scope = ''): float
    {
        return 1.0 - max(0.0, self::jobBonus($fit, $activityId, $scope, 'te') / 100.0);
    }

    /**
     * Cost modifier for a structure: (1 - effective job cost bonus / 100).
     */
    public static function costModifier(array $fit, int $activityId = 1, string $scope = ''): float
    {
        return 1.0 - max(0.0, self::jobBonus($fit, $activityId, $scope, 'cost') / 100.0);
    }

    /**
     * Parse a modifierInfo payload into the structure multiplier attributes a rig
     * writes. Pure, so it can be tested without a database.
     *
     * @return array<int, int>
     */
    public static function writtenAttributes(?string $modifierInfoJson): array
    {
        if ($modifierInfoJson === null || $modifierInfoJson === '') {
            return [];
        }

        $info = json_decode($modifierInfoJson, true);

        if (!is_array($info)) {
            return [];
        }

        $attributes = [];

        foreach ($info as $modifier) {
            if (!is_array($modifier)) {
                continue;
            }

            // Only the structure-side item modifiers are rig scopes; the ship-side
            // and location-group modifiers belong to conversion rigs.
            if (($modifier['domain'] ?? null) !== 'structureID') {
                continue;
            }

            if (($modifier['func'] ?? null) !== 'ItemModifier') {
                continue;
            }

            $attributeId = (int) ($modifier['modifiedAttributeID'] ?? 0);

            if ($attributeId > 0) {
                $attributes[] = $attributeId;
            }
        }

        return $attributes;
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * Bonus for one job, falling back to the fit-wide value when the scope cannot
     * be resolved.
     */
    private static function jobBonus(array $fit, int $activityId, string $scope, string $label): float
    {
        if ($scope === '') {
            return $fit[$label . '_bonus'];
        }

        return self::bonusesFor($fit, $activityId, $scope)[$label];
    }

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

                // A rig usually carries only its own bonus, but the XL-Set
                // "Efficiency" rigs carry TE and ME together, so collect every
                // non-zero bonus attribute instead of the first one found.
                $bonuses = [];

                foreach (RigAttributes::BONUS_LABELS as $attributeId => $label) {
                    $value = self::attribute($attributes, $attributeId);

                    if ($value !== null && abs($value) > 0.0) {
                        $bonuses[$label] = $value;
                    }
                }

                if (empty($bonuses)) {
                    continue;
                }

                // Present-but-zero is a real value: a rig can be disabled in one
                // band. Only a genuinely absent attribute falls back to the band
                // default.
                $multiplierValue = $multiplierAttribute
                    ? self::attribute($attributes, $multiplierAttribute)
                    : null;

                $multiplier = $multiplierValue ?? $fallback;

                $effectiveByLabel = [];

                foreach ($bonuses as $label => $raw) {
                    $effectiveByLabel[$label] = round(abs($raw) * $multiplier, 2);
                }

                // The display fields keep the first bonus as the rig's primary one;
                // effective_by_label is what the scope resolver uses.
                $primary = array_key_first($bonuses);
                $written = self::writtenAttributesFor((int) $item->type_id);

                $rigs[] = [
                    'type_id' => (int) $item->type_id,
                    'name' => optional($item->type)->typeName ?? ('Rig #' . $item->type_id),
                    'bonus' => $primary,
                    'raw' => round($bonuses[$primary], 2),
                    'multiplier' => round($multiplier, 2),
                    'effective' => $effectiveByLabel[$primary],
                    'bonuses' => $bonuses,
                    'effective_by_label' => $effectiveByLabel,
                    'attributes' => $written,
                    'scopes' => array_values(array_unique(array_filter(array_map(
                        fn (int $attributeId) => RigScope::tokenForAttribute($attributeId),
                        $written
                    )))),
                ];

                foreach ($effectiveByLabel as $label => $effective) {
                    $best[$label] = max($best[$label], $effective);
                }
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
     * The multiplier attributes a rig type writes, from its dogma effects.
     *
     * @return array<int, int>
     */
    private static function writtenAttributesFor(int $typeId): array
    {
        if (isset(self::$effectMemo[$typeId])) {
            return self::$effectMemo[$typeId];
        }

        if (!IndustryData::isEffectsInstalled() || !Schema::hasTable('dgmTypeEffects')) {
            return self::$effectMemo[$typeId] = [];
        }

        try {
            $effectIds = DB::table('dgmTypeEffects')
                ->where('typeID', $typeId)
                ->pluck('effectID')
                ->map(fn ($v) => (int) $v)
                ->all();

            $written = [];

            foreach ($effectIds as $effectId) {
                $row = DB::table(IndustryData::TABLE_EFFECTS)
                    ->where('effectID', $effectId)
                    ->first(['modifierInfo']);

                $written = array_merge($written, self::writtenAttributes($row->modifierInfo ?? null));
            }

            return self::$effectMemo[$typeId] = array_values(array_unique($written));
        } catch (\Throwable $e) {
            return self::$effectMemo[$typeId] = [];
        }
    }

    /**
     * One dogma attribute's value from a rig's attribute collection.
     *
     * Returns null when the attribute is absent, which is different from a stored
     * zero.
     *
     * @param \Illuminate\Support\Collection $attributes
     */
    private static function attribute($attributes, int $attributeId): ?float
    {
        $row = $attributes->firstWhere('attributeID', $attributeId);

        if (!$row) {
            return null;
        }

        return $row->valueFloat !== null ? (float) $row->valueFloat : (float) $row->valueInt;
    }
}
