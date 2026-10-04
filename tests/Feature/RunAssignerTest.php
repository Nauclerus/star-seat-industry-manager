<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\RigScope;
use IndustryManager\Helpers\StructureTypes;
use IndustryManager\Services\RunAssigner;
use IndustryManager\Tests\TestCase;

/**
 * The per-run assignment. Structures are injected as plain arrays, which is the
 * shape StructureService::forUser() produces, so the ranking is exercised without
 * SeAT's corporation tables.
 */
class RunAssignerTest extends TestCase
{
    public function test_the_structure_with_a_rig_covering_the_job_wins(): void
    {
        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Azbel - component rig', StructureTypes::AZBEL, [
                    $this->rig(43867, 'Advanced Component ME', ['me' => -2.0], [2557, 2558]),
                ]),
                $this->structure(2, 'Raitaru - ship rig', StructureTypes::RAITARU, [
                    $this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [2550, 2551]),
                ]),
            ]),
            scopeResolver: fn (int $productId) => $this->scopeForProduct($productId)
        );

        // A component blueprint must go to the structure with the component rig,
        // even though both structures have an ME rig fitted.
        $component = $assigner->for($this->recipe(9001, 334, IndustryActivity::MANUFACTURING));

        $this->assertSame(1, $component['structure_id']);
        $this->assertSame(4.2, $component['me_bonus']);
        $this->assertSame(RigScope::ADV_COMPONENT, $component['scope']);

        // A ship blueprint must go to the structure with the ship rig.
        $ship = $assigner->for($this->recipe(9002, 324, IndustryActivity::MANUFACTURING));

        $this->assertSame(2, $ship['structure_id']);
        $this->assertSame(4.2, $ship['me_bonus']);
        $this->assertSame(RigScope::ADV_SMALL_SHIP, $ship['scope']);
    }

    public function test_a_job_no_fitted_rig_covers_gets_no_bonus(): void
    {
        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Azbel - component rig', StructureTypes::AZBEL, [
                    $this->rig(43867, 'Advanced Component ME', ['me' => -2.0], [2557, 2558]),
                ]),
            ]),
            scopeResolver: fn (int $productId) => RigScope::STRUCTURE
        );

        $job = $assigner->for($this->recipe(9003, 1404, IndustryActivity::MANUFACTURING));

        $this->assertSame(1, $job['structure_id']);
        $this->assertSame(0.0, $job['me_bonus']);
        $this->assertSame(1.0, $job['material_modifier']);
        $this->assertSame('uncovered', $job['source']);
    }

    public function test_refineries_are_only_offered_for_reactions(): void
    {
        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Athanor', StructureTypes::ATHANOR, [
                    $this->rig(43867, 'Reaction Comp', ['me' => -2.0], [2718, 2717]),
                ]),
            ]),
            scopeResolver: fn (int $productId) => RigScope::REACTION_CHEMICAL
        );

        // A manufacturing job cannot be run in a refinery, so nothing is offered.
        $manufacturing = $assigner->for($this->recipe(9004, 334, IndustryActivity::MANUFACTURING));

        $this->assertNull($manufacturing['structure_id']);

        $reaction = $assigner->for($this->recipe(9005, 436, IndustryActivity::REACTIONS));

        $this->assertSame(1, $reaction['structure_id']);
        $this->assertSame(RigScope::REACTION_CHEMICAL, $reaction['scope']);
        $this->assertSame(4.2, $reaction['me_bonus']);
    }

    public function test_a_stored_override_beats_the_auto_best_pick(): void
    {
        DB::table(IndustryData::TABLE_RUNS)->insert([
            'project_id' => 7,
            'blueprint_type_id' => 9001,
            'activity_id' => IndustryActivity::MANUFACTURING,
            'product_type_id' => 334,
            'runs' => 1,
            'structure_id' => 2,
            'is_override' => 1,
        ]);

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Azbel - component rig', StructureTypes::AZBEL, [
                    $this->rig(43867, 'Advanced Component ME', ['me' => -2.0], [2557, 2558]),
                ]),
                $this->structure(2, 'Raitaru - ship rig', StructureTypes::RAITARU, [
                    $this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [2550, 2551]),
                ]),
            ]),
            scopeResolver: fn (int $productId) => RigScope::ADV_COMPONENT
        );

        $assigner->loadAssignments(7);

        $job = $assigner->for($this->recipe(9001, 334, IndustryActivity::MANUFACTURING));

        // The override structure has no component rig, so it gets no bonus — the
        // plan reports the override honestly rather than the better structure.
        $this->assertSame(2, $job['structure_id']);
        $this->assertSame(0.0, $job['me_bonus']);
        $this->assertTrue($job['is_override']);
        $this->assertSame('override', $job['source']);
    }

    public function test_activities_without_a_scope_split_use_the_fixed_attributes(): void
    {
        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Azbel', StructureTypes::AZBEL, [
                    $this->rig(43860, 'Invention Cost', ['cost' => -10.0], [2563, 2564]),
                ]),
            ]),
            scopeResolver: fn (int $productId) => 'general'
        );

        $job = $assigner->for($this->recipe(9006, 334, IndustryActivity::INVENTION));

        $this->assertSame('general', $job['scope']);
        $this->assertSame(21.0, $job['cost_bonus']);
        $this->assertSame(0.79, round($job['cost_modifier'], 2));
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * Resolve the scope the way the production path does, from the stub SDE rows.
     */
    private function scopeForProduct(int $productId): string
    {
        $row = DB::table('invTypes as t')
            ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
            ->leftJoin('invCategories as c', 'c.categoryID', '=', 'g.categoryID')
            ->where('t.typeID', $productId)
            ->first(['t.groupID', 'g.groupName', 'c.categoryName', 't.techLevel']);

        return $row
            ? RigScope::tokenForProduct((int) $row->groupID, $row->groupName, $row->categoryName, $row->techLevel)
            : 'general';
    }

    private function structure(int $structureId, string $name, int $typeId, array $rigs): array
    {
        $best = ['me' => 0.0, 'te' => 0.0, 'cost' => 0.0];

        foreach ($rigs as $rig) {
            foreach ($rig['effective_by_label'] as $label => $effective) {
                $best[$label] = max($best[$label], $effective);
            }
        }

        return [
            'structure_id' => $structureId,
            'name' => $name,
            'type_id' => $typeId,
            'security' => -0.5,
            'fit' => [
                'band' => 'nullsec',
                'multiplier' => 2.1,
                'rigs' => $rigs,
                'me_bonus' => $best['me'],
                'te_bonus' => $best['te'],
                'cost_bonus' => $best['cost'],
                'source' => $rigs ? 'fitted' : 'base',
            ],
        ];
    }

    private function rig(int $typeId, string $name, array $bonuses, array $attributes): array
    {
        $effectiveByLabel = [];

        foreach ($bonuses as $label => $raw) {
            $effectiveByLabel[$label] = round(abs($raw) * 2.1, 2);
        }

        $primary = array_key_first($bonuses);

        return [
            'type_id' => $typeId,
            'name' => $name,
            'bonus' => $primary,
            'raw' => round($bonuses[$primary], 2),
            'multiplier' => 2.1,
            'effective' => $effectiveByLabel[$primary],
            'bonuses' => $bonuses,
            'effective_by_label' => $effectiveByLabel,
            'attributes' => $attributes,
            'scopes' => [],
        ];
    }

    /**
     * A recipe in the shape ProductionCalculator::recipe() returns.
     */
    private function recipe(int $bp, int $groupId, int $activityId): array
    {
        // Seed the stub SDE so the scope resolver can classify the product.
        DB::table('invCategories')->insertOrIgnore([
            ['categoryID' => 6, 'categoryName' => 'Ship'],
            ['categoryID' => 17, 'categoryName' => 'Commodity'],
            ['categoryID' => 24, 'categoryName' => 'Reaction'],
            ['categoryID' => 65, 'categoryName' => 'Structure'],
        ]);

        $groups = [
            334 => ['categoryID' => 17, 'groupName' => 'Construction Components'],
            324 => ['categoryID' => 6, 'groupName' => 'Assault Frigate'],
            1404 => ['categoryID' => 65, 'groupName' => 'Engineering Complex'],
            436 => ['categoryID' => 24, 'groupName' => 'Simple Reaction'],
        ];

        DB::table('invGroups')->insertOrIgnore([
            'groupID' => $groupId,
            'categoryID' => $groups[$groupId]['categoryID'],
            'groupName' => $groups[$groupId]['groupName'],
        ]);

        DB::table('invTypes')->insertOrIgnore([
            'typeID' => $bp,
            'typeName' => 'Test ' . $bp,
            'groupID' => $groupId,
            'techLevel' => $groupId === 324 ? 2 : 1,
        ]);

        return [
            'blueprint_type_id' => $bp,
            'blueprint_name' => 'Test ' . $bp,
            'activity_id' => $activityId,
            'product_type_id' => $bp,
            'product_quantity' => 1,
            'time' => 3600,
            'materials' => [],
            'skills' => [],
        ];
    }
}
