<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\PiTier;

/**
 * PiSchematicService — resolves Planetary Industry factory schematics from the
 * registered SDE (planetSchematics + planetSchematicsTypeMap) and builds the
 * recursive P4 -> P0 input tree.
 *
 * PI has no ME/efficiency: input quantities are fixed, so the tree is
 * deterministic. Recipes are cached per SDE version like the manufacturing
 * recipes.
 */
class PiSchematicService
{
    private const TTL = 604800;

    private const MAX_DEPTH = 6;

    /** Per-request memo of the output->schematic map. */
    private static ?array $outputMapMemo = null;

    /**
     * productTypeID => schematicID for every schematic output.
     *
     * @return array<int,int>
     */
    public function outputMap(): array
    {
        if (self::$outputMapMemo !== null) {
            return self::$outputMapMemo;
        }

        if (! IndustryData::isPiInstalled()) {
            return self::$outputMapMemo = [];
        }

        $cacheKey = 'im:pi:outmap:' . $this->sdeVersion();

        return self::$outputMapMemo = Cache::remember($cacheKey, self::TTL, function () {
            return DB::table(IndustryData::TABLE_PI_TYPEMAP)
                ->where('isInput', 0)
                ->pluck('schematicID', 'typeID')
                ->map(fn ($v) => (int) $v)
                ->toArray();
        });
    }

    /**
     * Full schematic recipe (output + cycle + inputs), cached.
     */
    public function recipe(int $schematicId): ?array
    {
        if (! IndustryData::isPiInstalled()) {
            return null;
        }

        $cacheKey = 'im:pi:recipe:' . $this->sdeVersion() . ':' . $schematicId;

        return Cache::remember($cacheKey, self::TTL, function () use ($schematicId) {
            return $this->buildRecipe($schematicId);
        });
    }

    public function recipeForOutput(int $outputTypeId): ?array
    {
        $sid = $this->outputMap()[$outputTypeId] ?? null;

        return $sid ? $this->recipe($sid) : null;
    }

    /**
     * Recursive P-tier tree to produce $quantity units of $outputTypeId.
     * Returns the root node plus a rolled-up base (P0/leaf) material total.
     *
     * @return array{root:array, base_materials:array}|null
     */
    public function tree(int $outputTypeId, ?int $quantity = null): ?array
    {
        if (! IndustryData::isPiInstalled()) {
            return null;
        }

        $recipe = $this->recipeForOutput($outputTypeId);
        if (! $recipe) {
            return null;
        }

        $quantity = $quantity ?? ($recipe['output']['qty'] ?? 1);
        $base = [];
        $root = $this->expand($outputTypeId, max(1, $quantity), 0, [], $base);

        $baseList = array_values($base);
        usort($baseList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['root' => $root, 'base_materials' => $baseList];
    }

    /**
     * Browse list: every schematic with its output name + tier, for a
     * searchable catalogue.
     *
     * @return Collection<int,array>
     */
    public function allSchematics(): Collection
    {
        if (! IndustryData::isPiInstalled()) {
            return collect();
        }

        $cached = Cache::remember('im:pi:all:' . $this->sdeVersion(), self::TTL, function () {
            return DB::table(IndustryData::TABLE_PI_TYPEMAP . ' as m')
                ->join(IndustryData::TABLE_PI_SCHEMATICS . ' as s', 's.schematicID', '=', 'm.schematicID')
                ->leftJoin('invTypes as t', 't.typeID', '=', 'm.typeID')
                ->where('m.isInput', 0)
                ->orderBy('t.typeName')
                ->get([
                    'm.schematicID', 'm.typeID as output_type_id', 'm.quantity as output_qty',
                    's.schematicName', 's.cycleTime', 't.typeName as output_name', 't.marketGroupID',
                ])
                ->map(fn ($r) => [
                    'schematic_id' => (int) $r->schematicID,
                    'schematic_name' => $r->schematicName,
                    'output_type_id' => (int) $r->output_type_id,
                    'output_name' => $r->output_name ?? ('Type #' . $r->output_type_id),
                    'output_qty' => (int) $r->output_qty,
                    'cycle_time' => (int) $r->cycleTime,
                    'tier' => PiTier::tierForMarketGroup($r->marketGroupID !== null ? (int) $r->marketGroupID : null),
                ])
                ->all();
        });

        return collect($cached);
    }

    // ----------------------------------------------------------------------

    private function buildRecipe(int $schematicId): ?array
    {
        $base = DB::table(IndustryData::TABLE_PI_SCHEMATICS)
            ->where('schematicID', $schematicId)
            ->first(['schematicName', 'cycleTime']);

        if (! $base) {
            return null;
        }

        $rows = DB::table(IndustryData::TABLE_PI_TYPEMAP . ' as m')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'm.typeID')
            ->where('m.schematicID', $schematicId)
            ->get(['m.typeID', 'm.quantity', 'm.isInput', 't.typeName', 't.marketGroupID']);

        $output = null;
        $inputs = [];
        foreach ($rows as $r) {
            $entry = [
                'type_id' => (int) $r->typeID,
                'name' => $r->typeName ?? ('Type #' . $r->typeID),
                'qty' => (int) $r->quantity,
                'tier' => PiTier::tierForMarketGroup($r->marketGroupID !== null ? (int) $r->marketGroupID : null),
            ];
            if ((int) $r->isInput === 0) {
                $output = $entry;
            } else {
                $inputs[] = $entry;
            }
        }

        return [
            'schematic_id' => $schematicId,
            'name' => $base->schematicName,
            'cycle_time' => (int) $base->cycleTime,
            'output' => $output,
            'inputs' => $inputs,
        ];
    }

    private function expand(int $typeId, int $quantity, int $depth, array $path, array &$base): array
    {
        $recipe = $this->recipeForOutput($typeId);
        $isCycle = in_array($typeId, $path, true);

        // Leaf: no schematic produces this (raw P0), or depth/cycle guard.
        if (! $recipe || $isCycle || $depth >= self::MAX_DEPTH) {
            $name = $recipe['output']['name'] ?? $this->typeName($typeId);
            $tier = $recipe['output']['tier'] ?? null;
            if (! isset($base[$typeId])) {
                $base[$typeId] = ['type_id' => $typeId, 'name' => $name, 'tier' => $tier, 'quantity' => 0];
            }
            $base[$typeId]['quantity'] += $quantity;

            return [
                'type_id' => $typeId,
                'name' => $name,
                'tier' => $tier,
                'quantity' => $quantity,
                'leaf' => true,
                'children' => [],
            ];
        }

        $outQty = max(1, (int) ($recipe['output']['qty'] ?? 1));
        $cycles = (int) ceil($quantity / $outQty);
        $childPath = array_merge($path, [$typeId]);

        $children = [];
        foreach ($recipe['inputs'] as $in) {
            $needed = $cycles * $in['qty'];
            $children[] = $this->expand($in['type_id'], $needed, $depth + 1, $childPath, $base);
        }

        return [
            'type_id' => $typeId,
            'name' => $recipe['output']['name'] ?? $this->typeName($typeId),
            'tier' => $recipe['output']['tier'] ?? null,
            'quantity' => $quantity,
            'cycles' => $cycles,
            'cycle_time' => $recipe['cycle_time'],
            'leaf' => false,
            'children' => $children,
        ];
    }

    private function typeName(int $typeId): string
    {
        $n = DB::table('invTypes')->where('typeID', $typeId)->value('typeName');

        return $n ?: ('Type #' . $typeId);
    }

    private function sdeVersion(): string
    {
        return IndustryData::recipeVersion();
    }
}
