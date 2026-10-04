<?php

namespace IndustryManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\RigAttributes;
use IndustryManager\Helpers\RigScope;
use IndustryManager\Helpers\StructureTypes;

/**
 * RunAssigner — for one run in a production tree, which structure and which
 * character it should be done in.
 *
 * Structure: the fitted rig that covers this job's scope decides. A structure with
 * no rig covering the scope gets no bonus at all, so it ranks below one that does.
 * That is why the ranking is per job rather than per structure — the same fit is
 * the right answer for a component blueprint and the wrong answer for a ship one.
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

    /** @var array<int, string> */
    private array $scopeMemo = [];

    /**
     * Injectable so the ranking can be tested without SeAT's SDE tables. Defaults
     * to the invTypes/invGroups/invCategories lookup.
     *
     * @var callable(int):string
     */
    private $scopeResolver;

    public function __construct(
        ?Collection $structures = null,
        ?SkillService $skills = null,
        ?CharacterResolver $characters = null,
        ?callable $scopeResolver = null
    ) {
        $this->structures = $structures ?? collect();
        $this->skills = $skills ?? new SkillService();
        $this->characters = $characters ?? new CharacterResolver();
        $this->scopeResolver = $scopeResolver ?? fn (int $productId) => $this->lookupScope($productId);
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
     *               character_name:?string, me_bonus:float, te_bonus:float, cost_bonus:float,
     *               material_modifier:float, time_modifier:float, cost_modifier:float,
     *               source:string, is_override:bool}
     */
    public function for(array $recipe): array
    {
        $activityId = (int) ($recipe['activity_id'] ?? IndustryActivity::MANUFACTURING);
        $productId = $recipe['product_type_id'] !== null ? (int) $recipe['product_type_id'] : null;
        $scope = $this->scopeFor($productId, $activityId);

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
            if (!$this->canRun($structure, $activityId)) {
                continue;
            }

            $fit = $structure['fit'] ?? StructureIndustryRigs::base(RigAttributes::securityBand($structure['security'] ?? null));
            $candidate = StructureIndustryRigs::bonusesFor($fit, $activityId, $scope);

            $score = $candidate['me'] + $candidate['te'] + $candidate['cost'];

            if ($best === null || $score > $best['score']) {
                $best = ['structure' => $structure, 'score' => $score, 'job' => $candidate];
            }
        }

        if ($best !== null) {
            $job = $best['job'];
            $chosen = $best['structure'];
        } else {
            $chosen = null;
        }

        // A stored assignment overrides the auto-best pick.
        $key = (int) ($recipe['blueprint_type_id'] ?? 0) . ':' . $activityId;

        if (isset($this->assignments[$key])) {
            $stored = $this->assignments[$key];

            if ($stored['structure_id'] !== null) {
                foreach ($this->structures as $structure) {
                    if ((int) $structure['structure_id'] === $stored['structure_id']) {
                        $chosen = $structure;
                        $job = StructureIndustryRigs::bonusesFor(
                            $structure['fit'] ?? StructureIndustryRigs::base(RigAttributes::securityBand($structure['security'] ?? null)),
                            $activityId,
                            $scope
                        );

                        break;
                    }
                }
            }

            $job['source'] = 'override';
        }

        $character = $this->pickCharacter($recipe);

        if (isset($this->assignments[$key]) && $this->assignments[$key]['character_id'] !== null) {
            $character = ['character_id' => $this->assignments[$key]['character_id'], 'name' => $this->characterName($character['character_id'])];
        }

        return [
            'scope' => $scope,
            'structure_id' => $chosen['structure_id'] ?? null,
            'structure_name' => $chosen['name'] ?? null,
            'character_id' => $character['character_id'] ?? null,
            'character_name' => $character['name'] ?? null,
            'me_bonus' => $job['me'],
            'te_bonus' => $job['te'],
            'cost_bonus' => $job['cost'],
            'material_modifier' => 1.0 - max(0.0, $job['me']) / 100.0,
            'time_modifier' => (1.0 - max(0.0, $job['te']) / 100.0) * ($character['time_multiplier'] ?? 1.0),
            'cost_modifier' => 1.0 - max(0.0, $job['cost']) / 100.0,
            'source' => $job['source'],
            'is_override' => isset($this->assignments[$key]),
        ];
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * Refineries run reactions; engineering complexes run everything else.
     */
    private function canRun(array $structure, int $activityId): bool
    {
        $typeId = (int) ($structure['type_id'] ?? 0);

        if ($activityId === IndustryActivity::REACTIONS) {
            return in_array($typeId, StructureTypes::REFINERIES, true);
        }

        return in_array($typeId, StructureTypes::ENGINEERING_COMPLEXES, true);
    }

    /**
     * Scope for a run. Activities that are not split by scope return 'general';
     * reactions and manufacturing come from the product's inventory group.
     */
    private function scopeFor(?int $productId, int $activityId): string
    {
        if ($productId === null) {
            return 'general';
        }

        if ($activityId !== IndustryActivity::MANUFACTURING && $activityId !== IndustryActivity::REACTIONS) {
            return 'general';
        }

        if (isset($this->scopeMemo[$productId])) {
            return $this->scopeMemo[$productId];
        }

        return $this->scopeMemo[$productId] = ($this->scopeResolver)($productId);
    }

    /**
     * The production scope resolver: product group -> scope token.
     */
    private function lookupScope(int $productId): string
    {
        if (!IndustryData::hasTable('invGroups')) {
            return 'general';
        }

        try {
            $row = DB::table('invTypes as t')
                ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
                ->leftJoin('invCategories as c', 'c.categoryID', '=', 'g.categoryID')
                ->where('t.typeID', $productId)
                ->first(['t.groupID', 'g.groupName', 'c.categoryName', 't.techLevel']);

            if (!$row) {
                return 'general';
            }

            return RigScope::tokenForProduct(
                $row->groupID !== null ? (int) $row->groupID : null,
                $row->groupName,
                $row->categoryName,
                $row->techLevel !== null ? (int) $row->techLevel : null
            );
        } catch (\Throwable $e) {
            return 'general';
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

            $best = $this->skills->bestQualifiedCharacter($characterIds, $recipe['skills'] ?? []);

            if ($best === null) {
                // Nobody fully qualifies, so fall back to the fastest character rather
                // than leaving the run unassigned; the UI flags the missing skills.
                $best = $this->skills->bestTimeMultiplier($characterIds);

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
