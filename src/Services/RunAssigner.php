<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\AssemblyLines;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\RigAttributes;
use IndustryManager\Helpers\RigScope;
use IndustryManager\Helpers\StructureBonuses;
use IndustryManager\Helpers\StructureTypes;

/**
 * RunAssigner — for one run in a production tree, which structure and which
 * character it should be done in.
 *
 * Structure: it has to be able to run the activity at all — the service module
 * fitted in it has to provide an assembly line for this activity and this product,
 * and a reaction has to be in security 0.4 or lower. Among the structures that can,
 * the fitted rig that covers this job's scope decides, because a structure with no
 * rig covering the scope gets no rig bonus at all. That is why the ranking is per
 * job rather than per structure — the same fit is the right answer for a component
 * blueprint and the wrong answer for a ship one. The structure's own bonus (an
 * Azbel is 4% cheaper and 20% faster than a Raitaru) is part of the same score.
 *
 * Character: the one that meets the recipe's skill requirements, tie-broken on the
 * best time multiplier. Skills affect time only, never quantities.
 *
 * A stored assignment wins over the auto-best pick, so an override on an objective
 * propagates to the runs under it.
 */
final class RunAssigner
{
    private Collection $structures;

    private SkillService $skills;

    private CharacterResolver $characters;

    /**
     * Stored assignments keyed by `blueprint:activity`.
     *
     * @var array<string, array>
     */
    private array $assignments = [];

    /** @var array<int, array{scope:string, groupID:?int, categoryID:?int}> */
    private array $productMemo = [];

    /**
     * Injectable so the ranking can be tested without SeAT's SDE tables. Defaults
     * to the invTypes/invGroups/invCategories lookup.
     *
     * @var callable(int):array
     */
    private $productResolver;

    public function __construct(
        ?Collection $structures = null,
        ?SkillService $skills = null,
        ?CharacterResolver $characters = null,
        ?callable $productResolver = null
    ) {
        $this->structures = $structures ?? collect();
        $this->skills = $skills ?? new SkillService();
        $this->characters = $characters ?? new CharacterResolver();
        $this->productResolver = $productResolver ?? fn (int $productId) => $this->lookupProduct($productId);
    }

    /**
     * Load the persisted plan for a project so stored assignments win over the
     * auto-best pick.
     */
    public function loadAssignments(int $projectId): void
    {
        if (!IndustryData::hasTable(IndustryData::TABLE_RUNS)) {
            return;
        }

        try {
            $rows = DB::table(IndustryData::TABLE_RUNS)
                ->where('project_id', $projectId)
                ->get(['blueprint_type_id', 'activity_id', 'structure_id', 'character_id', 'is_override']);

            foreach ($rows as $row) {
                $key = (int) $row->blueprint_type_id . ':' . (int) $row->activity_id;

                if ($row->is_override) {
                    $this->assignments[$key] = [
                        'structure_id' => $row->structure_id !== null ? (int) $row->structure_id : null,
                        'character_id' => $row->character_id !== null ? (int) $row->character_id : null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // A missing or unreadable plan must not stop the tree from computing.
            $this->assignments = [];
        }
    }

    /**
     * The assignment for one run.
     *
     * @param  array  $recipe  a recipe as ProductionCalculator::recipe() returns it
     * @return array{scope:string, structure_id:?int, structure_name:?string, character_id:?int,
     *               character_name:?string, service:?string, structure_bonus:array,
     *               me_bonus:float, te_bonus:float, cost_bonus:float,
     *               material_modifier:float, time_modifier:float, cost_modifier:float,
     *               source:string, is_override:bool}
     */
    public function for(array $recipe): array
    {
        $activityId = (int) ($recipe['activity_id'] ?? IndustryActivity::MANUFACTURING);
        $productId = $recipe['product_type_id'] !== null ? (int) $recipe['product_type_id'] : null;
        $product = $this->productInfo($productId);
        $scope = $activityId === IndustryActivity::MANUFACTURING || $activityId === IndustryActivity::REACTIONS
            ? $product['scope']
            : 'general';

        $job = [
            'me' => 0.0,
            'te' => 0.0,
            'cost' => 0.0,
            'scope' => $scope,
            'source' => 'base',
            'rig' => null,
        ];

        $best = null;

        foreach ($this->structures as $structure) {
            $line = $this->lineFor($structure, $activityId, $product);

            if ($line === null) {
                continue;
            }

            $fit = $structure['fit'] ?? StructureIndustryRigs::base(RigAttributes::securityBand($structure['security'] ?? null));
            $candidate = StructureIndustryRigs::bonusesFor($fit, $activityId, $scope);
            $bonus = StructureBonuses::for((int) ($structure['type_id'] ?? 0), $activityId);

            // Bigger is better: the rig percentages and the structure multipliers
            // all point the same way once the multipliers are read as savings.
            $score = $candidate['me'] + $candidate['te'] + $candidate['cost']
                + (1.0 - $bonus['material']) + (1.0 - $bonus['time']) + (1.0 - $bonus['cost']);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'structure' => $structure,
                    'score' => $score,
                    'job' => $candidate,
                    'line' => $line,
                    'bonus' => $bonus,
                ];
            }
        }

        if ($best !== null) {
            $job = $best['job'];
            $chosen = $best['structure'];
            $line = $best['line'];
            $bonus = $best['bonus'];
        } else {
            $chosen = null;
            $line = null;
            $bonus = ['material' => 1.0, 'cost' => 1.0, 'time' => 1.0];
        }

        // A stored assignment overrides the auto-best pick.
        $key = (int) ($recipe['blueprint_type_id'] ?? 0) . ':' . $activityId;

        if (isset($this->assignments[$key])) {
            $stored = $this->assignments[$key];

            if ($stored['structure_id'] !== null) {
                foreach ($this->structures as $structure) {
                    if ((int) $structure['structure_id'] !== $stored['structure_id']) {
                        continue;
                    }

                    $chosen = $structure;
                    $line = $this->lineFor($structure, $activityId, $product);
                    $job = StructureIndustryRigs::bonusesFor(
                        $structure['fit'] ?? StructureIndustryRigs::base(RigAttributes::securityBand($structure['security'] ?? null)),
                        $activityId,
                        $scope
                    );
                    $bonus = StructureBonuses::for((int) ($structure['type_id'] ?? 0), $activityId);

                    break;
                }
            }

            $job['source'] = 'override';
        }

        $character = $this->pickCharacter($recipe);

        if (isset($this->assignments[$key]) && $this->assignments[$key]['character_id'] !== null) {
            $character = ['character_id' => $this->assignments[$key]['character_id'], 'name' => $this->characterName($character['character_id'])];
        }

        // Rig bonuses are percentages, the structure bonuses are multipliers, and a
        // run is affected by both: an Azbel with a component rig is 15% faster than
        // base before the character's skills are applied on top.
        $materialModifier = (1.0 - max(0.0, $job['me']) / 100.0) * $bonus['material'];
        $timeModifier = (1.0 - max(0.0, $job['te']) / 100.0) * $bonus['time'] * ($character['time_multiplier'] ?? 1.0);
        $costModifier = (1.0 - max(0.0, $job['cost']) / 100.0) * $bonus['cost'];

        return [
            'scope' => $scope,
            'structure_id' => $chosen['structure_id'] ?? null,
            'structure_name' => $chosen['name'] ?? null,
            'structure_type_id' => $chosen['type_id'] ?? null,
            'character_id' => $character['character_id'] ?? null,
            'character_name' => $character['name'] ?? null,
            'service' => $line['name'] ?? null,
            'structure_bonus' => $bonus,
            'me_bonus' => $job['me'],
            'te_bonus' => $job['te'],
            'cost_bonus' => $job['cost'],
            'material_modifier' => $materialModifier,
            'time_modifier' => $timeModifier,
            'cost_modifier' => $costModifier,
            'source' => $job['source'],
            'is_override' => isset($this->assignments[$key]),
        ];
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * The assembly line in this structure that can run this activity for this
     * product, or null when the structure cannot.
     *
     * A structure is able when a service module fitted in it provides a line for
     * the activity that accepts the product. Reactions additionally need the
     * structure to be in security 0.4 or lower.
     *
     * Without the imported capability map there is nothing to check against, and
     * the curated structure-type gate stands in.
     *
     * @param  array  $product  from productInfo()
     * @return array|null
     */
    private function lineFor(array $structure, int $activityId, array $product): ?array
    {
        $typeId = (int) ($structure['type_id'] ?? 0);
        $security = $structure['security'] !== null ? (float) $structure['security'] : null;

        if (!IndustryData::isCapabilityInstalled()) {
            if (!StructureTypes::securityAllows($security, $activityId, $product['groupID'])) {
                return null;
            }

            return StructureTypes::fallbackAllows($typeId, $activityId, $product['groupID'])
                ? ['assemblyLineID' => null, 'name' => null, 'activityID' => $activityId]
                : null;
        }

        $line = AssemblyLines::find(
            $structure['lines'] ?? [],
            $activityId,
            $product['groupID'],
            $product['categoryID']
        );

        if ($line === null) {
            return null;
        }

        // The module that provides the line decides where it may be onlined: the
        // reactor lines carry a lowsec limit and the capital shipyard lines carry
        // the same, which is where "no reactions in highsec" and "no capitals in
        // highsec" come from. A line whose module attributes could not be read
        // keeps the curated limit.
        if (isset($line['max_security'])) {
            return $security !== null && $security <= (float) $line['max_security'] ? $line : null;
        }

        return StructureTypes::securityAllows($security, $activityId, $product['groupID']) ? $line : null;
    }

    /**
     * What a product is, for both the rig scope and the assembly-line match.
     *
     * @return array{scope:string, groupID:?int, categoryID:?int}
     */
    private function productInfo(?int $productId): array
    {
        if ($productId === null) {
            return ['scope' => 'general', 'groupID' => null, 'categoryID' => null];
        }

        if (isset($this->productMemo[$productId])) {
            return $this->productMemo[$productId];
        }

        return $this->productMemo[$productId] = ($this->productResolver)($productId);
    }

    /**
     * The production scope resolver: product group -> scope token.
     *
     * @return array{scope:string, groupID:?int, categoryID:?int}
     */
    private function lookupProduct(int $productId): array
    {
        if (!IndustryData::hasTable('invGroups')) {
            return ['scope' => 'general', 'groupID' => null, 'categoryID' => null];
        }

        try {
            $row = DB::table('invTypes as t')
                ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
                ->leftJoin('invCategories as c', 'c.categoryID', '=', 'g.categoryID')
                ->where('t.typeID', $productId)
                ->first(['t.groupID', 'g.groupName', 'g.categoryID', 'c.categoryName', 't.techLevel']);

            if (!$row) {
                return ['scope' => 'general', 'groupID' => null, 'categoryID' => null];
            }

            $groupID = $row->groupID !== null ? (int) $row->groupID : null;
            $categoryID = $row->categoryID !== null ? (int) $row->categoryID : null;

            return [
                'scope' => RigScope::tokenForProduct(
                    $groupID,
                    $row->groupName,
                    $row->categoryName,
                    $row->techLevel !== null ? (int) $row->techLevel : null
                ),
                'groupID' => $groupID,
                'categoryID' => $categoryID,
            ];
        } catch (\Throwable $e) {
            return ['scope' => 'general', 'groupID' => null, 'categoryID' => null];
        }
    }

    /**
     * @return array{character_id:?int, name:?string, time_multiplier:float}
     */
    private function pickCharacter(array $recipe): array
    {
        try {
            $characterIds = $this->characters->characterIds();

            if (empty($characterIds)) {
                return ['character_id' => null, 'name' => null, 'time_multiplier' => 1.0];
            }

            // The activity decides which skills count: a copying job is faster with
            // Science, a reaction with Reactions, and only manufacturing reads Industry.
            $activityId = (int) ($recipe['activity_id'] ?? IndustryActivity::MANUFACTURING);

            $best = $this->skills->bestQualifiedCharacter($characterIds, $recipe['skills'] ?? [], $activityId);

            if ($best === null) {
                // Nobody fully qualifies, so fall back to the fastest character rather
                // than leaving the run unassigned; the UI flags the missing skills.
                $best = $this->skills->bestTimeMultiplier($characterIds, $activityId);

                if ($best === null) {
                    return ['character_id' => null, 'name' => null, 'time_multiplier' => 1.0];
                }
            }

            return [
                'character_id' => (int) $best['character_id'],
                'name' => $this->characterName((int) $best['character_id']),
                'time_multiplier' => (float) $best['time_multiplier'],
            ];
        } catch (\Throwable $e) {
            return ['character_id' => null, 'name' => null, 'time_multiplier' => 1.0];
        }
    }

    private function characterName(int $characterId): ?string
    {
        try {
            return DB::table('characters')->where('character_id', $characterId)->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
