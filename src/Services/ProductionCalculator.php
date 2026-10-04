<?php

namespace IndustryManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;

/**
 * ProductionCalculator — the heart of the plugin.
 *
 * Turns a blueprint + ME + runs into a material requirement, and recursively
 * into a full production tree down to base materials.
 *
 * Performance design:
 *   - A blueprint's RECIPE (which materials, base quantities, time, product) is
 *     static SDE data, independent of ME/runs. We cache it keyed by the recipe
 *     import version (IndustryData::recipeVersion), so re-running the importer
 *     invalidates it and it otherwise lives ~forever. ME/runs are applied
 *     cheaply at compute time.
 *   - The product->blueprint reverse map ("can this material be built?") is
 *     built once and cached as a single blob, plus memoised per request.
 *   - Recursion reuses cached recipes, so a deep tree is mostly array math.
 *
 * Correctness — EVE manufacturing material formula (per material):
 *     modifier  = (1 - ME/100) * structureModifier * rigModifier
 *     adjusted  = baseQuantity * runs * modifier
 *     required  = max(runs, ceil(round(adjusted, 2)))
 * The max(runs, …) floor enforces "at least 1 unit per run per material".
 * round-to-2 before ceil matches the in-game rounding.
 *
 * structureModifier and rigModifier default to 1.0 and are supplied by
 * StructureIndustryRigs when a structure is chosen — see the calculator's
 * `structure` parameter. Skills affect time only, never quantities, so they are
 * deliberately not part of this formula.
 */
class ProductionCalculator
{
    /** 7 days; the SDE-version cache key is the real invalidator. */
    private const RECIPE_TTL = 604800;

    private const DEFAULT_MAX_DEPTH = 5;

    /** Per-request memo of the buildable map, keyed by activityId. */
    private static array $buildableMapMemo = [];

    /**
     * Apply the EVE material formula to a single base quantity.
     */
    public function adjustedQuantity(
        int $baseQuantity,
        int $runs,
        float $meFraction,
        float $structureModifier = 1.0,
        float $rigModifier = 1.0
    ): int {
        $runs = max(1, $runs);
        $modifier = (1.0 - $meFraction) * $structureModifier * $rigModifier;
        $adjusted = $baseQuantity * $runs * $modifier;

        return (int) max($runs, (int) ceil(round($adjusted, 2)));
    }

    /**
     * productTypeID => blueprintTypeID for an activity (default manufacturing).
     * Lets the tree decide whether a material is itself buildable.
     *
     * @return array<int,int>
     */
    public function buildableMap(int $activityId = IndustryActivity::MANUFACTURING): array
    {
        if (isset(self::$buildableMapMemo[$activityId])) {
            return self::$buildableMapMemo[$activityId];
        }

        if (! IndustryData::isInstalled()) {
            return self::$buildableMapMemo[$activityId] = [];
        }

        $cacheKey = 'im:buildable_map:' . $this->sdeVersion() . ':' . $activityId;

        $map = Cache::remember($cacheKey, self::RECIPE_TTL, function () use ($activityId) {
            return DB::table(IndustryData::TABLE_PRODUCTS)
                ->where('activityID', $activityId)
                ->pluck('typeID', 'productTypeID')   // key = product, value = blueprint
                ->map(fn ($v) => (int) $v)
                ->toArray();
        });

        return self::$buildableMapMemo[$activityId] = $map;
    }

    /**
     * The full static recipe for a blueprint+activity, cached.
     *
     * @return array|null  null when SDE absent or no such recipe
     */
    public function recipe(int $blueprintTypeId, int $activityId = IndustryActivity::MANUFACTURING): ?array
    {
        if (! IndustryData::isInstalled()) {
            return null;
        }

        $cacheKey = 'im:recipe:' . $this->sdeVersion() . ':' . $activityId . ':' . $blueprintTypeId;

        return Cache::remember($cacheKey, self::RECIPE_TTL, function () use ($blueprintTypeId, $activityId) {
            return $this->buildRecipe($blueprintTypeId, $activityId);
        });
    }

    /**
     * Build a recursive production tree with ME/runs applied, plus a rolled-up
     * base-material total across the whole tree.
     *
     * Options: me (0-10), runs, max_depth, sub_component_me, activity_id,
     * structure_modifier, rig_modifier.
     *
     * @return array{root:array, base_materials:array, assumptions:array}|null
     */
    public function tree(int $blueprintTypeId, array $opts = []): ?array
    {
        if (! IndustryData::isInstalled()) {
            return null;
        }

        $me = (float) ($opts['me'] ?? 0);
        $runs = max(1, (int) ($opts['runs'] ?? 1));
        $maxDepth = (int) ($opts['max_depth'] ?? self::DEFAULT_MAX_DEPTH);
        $subMe = (float) ($opts['sub_component_me'] ?? 0);
        $activityId = (int) ($opts['activity_id'] ?? IndustryActivity::MANUFACTURING);
        $structureModifier = (float) ($opts['structure_modifier'] ?? 1.0);
        $rigModifier = (float) ($opts['rig_modifier'] ?? 1.0);
        $assigner = $opts['assigner'] ?? null;

        if ($this->recipe($blueprintTypeId, $activityId) === null) {
            return null;
        }

        $baseTotals = [];
        $root = $this->expand($blueprintTypeId, $activityId, $runs, $me, $subMe, $maxDepth, 0, [], $baseTotals, $structureModifier, $rigModifier, $assigner);

        $baseList = array_values($baseTotals);
        usort($baseList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'root' => $root,
            'base_materials' => $baseList,
            'assumptions' => [
                'me' => $me,
                'runs' => $runs,
                'sub_component_me' => $subMe,
                'max_depth' => $maxDepth,
                'activity_id' => $activityId,
                'structure_modifier' => $structureModifier,
                'rig_modifier' => $rigModifier,
            ],
        ];
    }

    /**
     * Industry Trace — per-material step-by-step breakdown of the ME formula,
     * exposing every intermediate value so an operator can reconcile a single
     * line against the in-game industry window and pinpoint any divergence.
     *
     * @return array{recipe:array, rows:array, me:int, runs:int}|null
     */
    public function trace(int $blueprintTypeId, int $me, int $runs, int $activityId = IndustryActivity::MANUFACTURING, float $structureModifier = 1.0, float $rigModifier = 1.0): ?array
    {
        if (! IndustryData::isInstalled()) {
            return null;
        }

        $recipe = $this->recipe($blueprintTypeId, $activityId);
        if (! $recipe) {
            return null;
        }

        $runs = max(1, $runs);
        $meFraction = max(0, min(10, $me)) / 100.0;

        $rows = [];
        foreach ($recipe['materials'] as $mat) {
            $base = $mat['base_quantity'];
            $afterRuns = $base * $runs;
            $modifier = (1.0 - $meFraction) * $structureModifier * $rigModifier;
            $adjusted = $afterRuns * $modifier;
            $rounded = round($adjusted, 2);
            $final = (int) max($runs, (int) ceil($rounded));

            $rows[] = [
                'type_id' => $mat['type_id'],
                'name' => $mat['name'],
                'base_quantity' => $base,
                'after_runs' => $afterRuns,
                'modifier' => $modifier,
                'adjusted' => $adjusted,
                'rounded' => $rounded,
                'final' => $final,
            ];
        }

        return [
            'recipe' => $recipe,
            'rows' => $rows,
            'me' => (int) $me,
            'runs' => $runs,
            'structure_modifier' => $structureModifier,
            'rig_modifier' => $rigModifier,
        ];
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private function buildRecipe(int $bp, int $activityId): ?array
    {
        $product = DB::table(IndustryData::TABLE_PRODUCTS)
            ->where('typeID', $bp)
            ->where('activityID', $activityId)
            ->first(['productTypeID', 'quantity']);

        $materials = DB::table(IndustryData::TABLE_MATERIALS . ' as m')
            ->leftJoin('invTypes as t', 't.typeID', '=', 'm.materialTypeID')
            ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
            ->where('m.typeID', $bp)
            ->where('m.activityID', $activityId)
            ->get(['m.materialTypeID', 'm.quantity', 't.typeName', 't.groupID', 'g.categoryID']);

        if ($materials->isEmpty() && $product === null) {
            return null;
        }

        $time = DB::table(IndustryData::TABLE_ACTIVITY)
            ->where('typeID', $bp)
            ->where('activityID', $activityId)
            ->value('time');

        $skills = [];
        if (IndustryData::hasTable(IndustryData::TABLE_SKILLS)) {
            $skills = DB::table(IndustryData::TABLE_SKILLS . ' as s')
                ->leftJoin('invTypes as t', 't.typeID', '=', 's.skillID')
                ->where('s.typeID', $bp)
                ->where('s.activityID', $activityId)
                ->get(['s.skillID', 's.level', 't.typeName'])
                ->map(fn ($r) => [
                    'skill_id' => (int) $r->skillID,
                    'name' => $r->typeName ?? ('Skill #' . $r->skillID),
                    'level' => (int) $r->level,
                ])
                ->all();
        }

        $blueprintName = DB::table('invTypes')->where('typeID', $bp)->value('typeName');
        $buildable = $this->buildableMap(IndustryActivity::MANUFACTURING);

        return [
            'blueprint_type_id' => $bp,
            'blueprint_name' => $blueprintName ?? ('Blueprint #' . $bp),
            'activity_id' => $activityId,
            'product_type_id' => $product->productTypeID ?? null,
            'product_quantity' => (int) ($product->quantity ?? 1),
            'time' => (int) ($time ?? 0),
            'materials' => $materials->map(function ($m) use ($buildable) {
                $mid = (int) $m->materialTypeID;

                return [
                    'type_id' => $mid,
                    'name' => $m->typeName ?? ('Type #' . $mid),
                    'group_id' => $m->groupID !== null ? (int) $m->groupID : null,
                    'category_id' => $m->categoryID !== null ? (int) $m->categoryID : null,
                    'base_quantity' => (int) $m->quantity,
                    'buildable_blueprint' => $buildable[$mid] ?? null,
                ];
            })->all(),
            'skills' => $skills,
        ];
    }

    /**
     * Recursive expansion. Buildable materials within depth/cycle limits get
     * their own child node; everything else accumulates into $baseTotals.
     */
    private function expand(
        int $bp,
        int $activityId,
        int $runs,
        float $me,
        float $subMe,
        int $maxDepth,
        int $depth,
        array $path,
        array &$baseTotals,
        float $structureModifier = 1.0,
        float $rigModifier = 1.0,
        ?RunAssigner $assigner = null
    ): array {
        $recipe = $this->recipe($bp, $activityId);
        $meFraction = $me / 100.0;
        $isCycle = in_array($bp, $path, true);
        $childPath = array_merge($path, [$bp]);

        // Per-run assignment: the structure whose fitted rig covers this job's
        // scope, and the character whose skills apply. When an assigner is present
        // it replaces the flat modifiers for this node.
        $assignment = $assigner ? $assigner->for($recipe) : null;

        $materialModifier = $assignment ? $assignment['material_modifier'] : $structureModifier * $rigModifier;
        $timeModifier = $assignment ? $assignment['time_modifier'] : 1.0;

        $node = [
            'blueprint_type_id' => $bp,
            'product_type_id' => $recipe['product_type_id'] ?? null,
            'name' => $recipe['blueprint_name'] ?? ('Blueprint #' . $bp),
            'runs' => $runs,
            'time' => $recipe['time'] ?? 0,
            'depth' => $depth,
            'structure_modifier' => $structureModifier,
            'rig_modifier' => $rigModifier,
            'material_modifier' => $materialModifier,
            'time_modifier' => $timeModifier,
            'adjusted_time' => (int) round(($recipe['time'] ?? 0) * $timeModifier),
            'assignment' => $assignment,
            'materials' => [],
        ];

        foreach ($recipe['materials'] as $mat) {
            $qty = $this->adjustedQuantity($mat['base_quantity'], $runs, $meFraction, $structureModifier, $rigModifier);

            if ($assignment) {
                $qty = $this->adjustedQuantity($mat['base_quantity'], $runs, $meFraction, 1.0, $assignment['material_modifier']);
            }

            $entry = [
                'type_id' => $mat['type_id'],
                'name' => $mat['name'],
                'quantity' => $qty,
                'buildable' => $mat['buildable_blueprint'] !== null,
                'children' => null,
            ];

            $canExpand = $mat['buildable_blueprint'] !== null
                && ! $isCycle
                && $depth < $maxDepth;

            if ($canExpand) {
                $subRecipe = $this->recipe($mat['buildable_blueprint'], IndustryActivity::MANUFACTURING);
                $perRun = max(1, (int) ($subRecipe['product_quantity'] ?? 1));
                $subRuns = (int) ceil($qty / $perRun);

                $entry['sub_runs'] = $subRuns;
                // Sub-components get their own assignment when an assigner is
                // present, which is the point of the plan: a sub-component may need
                // a different structure than the parent. Without an assigner they
                // inherit the same modifiers, which is the documented assumption.
                $entry['children'] = $this->expand(
                    $mat['buildable_blueprint'],
                    IndustryActivity::MANUFACTURING,
                    $subRuns,
                    $subMe,            // deeper levels use the sub-component ME assumption
                    $subMe,
                    $maxDepth,
                    $depth + 1,
                    $childPath,
                    $baseTotals,
                    $structureModifier,
                    $rigModifier,
                    $assigner
                );
            } else {
                // Leaf — acquire as-is. Roll into the base-material total.
                if (! isset($baseTotals[$mat['type_id']])) {
                    $baseTotals[$mat['type_id']] = [
                        'type_id' => $mat['type_id'],
                        'name' => $mat['name'],
                        'quantity' => 0,
                    ];
                }
                $baseTotals[$mat['type_id']]['quantity'] += $qty;
            }

            $node['materials'][] = $entry;
        }

        return $node;
    }

    private function sdeVersion(): string
    {
        return IndustryData::recipeVersion();
    }
}
