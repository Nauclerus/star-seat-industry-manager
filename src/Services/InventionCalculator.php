<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;

/**
 * InventionCalculator — reads the invention recipe (activityID 8) for a T1
 * blueprint: datacores, base success probability, invented T2 BPC + its base
 * run count, required skills, and time.
 *
 * Decryptor + skill modifiers are applied in the view (client-side) so the
 * user can compare options without round-trips; this service supplies the base
 * numbers. Cached per SDE version like the manufacturing recipes.
 */
class InventionCalculator
{
    private const TTL = 604800;

    public function invent(int $blueprintTypeId): ?array
    {
        if (! IndustryData::isInstalled()) {
            return null;
        }

        if (! IndustryData::hasTable(IndustryData::TABLE_PROBABILITIES)) {
            return null;
        }

        $cacheKey = 'im:invention:' . $this->sdeVersion() . ':' . $blueprintTypeId;

        return Cache::remember($cacheKey, self::TTL, function () use ($blueprintTypeId) {
            return $this->build($blueprintTypeId);
        });
    }

    private function build(int $bp): ?array
    {
        $act = IndustryActivity::INVENTION;

        $datacores = DB::table(IndustryData::TABLE_MATERIALS . ' as m')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'm.materialTypeID')
            ->where('m.typeID', $bp)
            ->where('m.activityID', $act)
            ->get(['m.materialTypeID', 'm.quantity', 't.typeName'])
            ->map(fn ($r) => [
                'type_id' => (int) $r->materialTypeID,
                'name' => $r->typeName ?? ('Type #' . $r->materialTypeID),
                'quantity' => (int) $r->quantity,
            ])->all();

        $products = DB::table(IndustryData::TABLE_PRODUCTS)
            ->where('typeID', $bp)
            ->where('activityID', $act)
            ->get(['productTypeID', 'quantity']);

        if (empty($datacores) && $products->isEmpty()) {
            return null;
        }

        // Probability per product (base success chance, 0..1).
        $probRows = DB::table(IndustryData::TABLE_PROBABILITIES)
            ->where('typeID', $bp)
            ->where('activityID', $act)
            ->pluck('probability', 'productTypeID');

        // Resolve product (T2 BPC) names, and the item each BPC builds.
        $productIds = $products->pluck('productTypeID')->all();
        $names = DB::table('invTypes')->whereIn('typeID', $productIds)->pluck('typeName', 'typeID');

        $outcomes = $products->map(function ($p) use ($probRows, $names) {
            $pid = (int) $p->productTypeID;

            return [
                'product_type_id' => $pid,
                'product_name' => $names[$pid] ?? ('Type #' . $pid),
                'base_runs' => (int) $p->quantity,
                'base_probability' => (float) ($probRows[$pid] ?? 0),
            ];
        })->all();

        $skills = DB::table(IndustryData::TABLE_SKILLS . ' as s')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.skillID')
            ->where('s.typeID', $bp)
            ->where('s.activityID', $act)
            ->get(['s.skillID', 's.level', 't.typeName'])
            ->map(fn ($r) => [
                'skill_id' => (int) $r->skillID,
                'name' => $r->typeName ?? ('Skill #' . $r->skillID),
                'level' => (int) $r->level,
            ])->all();

        $time = DB::table(IndustryData::TABLE_ACTIVITY)
            ->where('typeID', $bp)
            ->where('activityID', $act)
            ->value('time');

        $bpName = DB::table('invTypes')->where('typeID', $bp)->value('typeName');

        return [
            't1_blueprint_type_id' => $bp,
            't1_blueprint_name' => $bpName ?? ('Blueprint #' . $bp),
            'time' => (int) ($time ?? 0),
            'datacores' => $datacores,
            'skills' => $skills,
            'outcomes' => $outcomes,
        ];
    }

    private function sdeVersion(): string
    {
        try {
            $v = setting('installed_sde', true);

            return $v ? (string) $v : 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
