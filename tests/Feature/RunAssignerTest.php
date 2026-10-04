<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use IndustryManager\Helpers\AssemblyLines;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\RigScope;
use IndustryManager\Helpers\ServiceModules;
use IndustryManager\Helpers\StructureBonuses;
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
    protected function setUp(): void
    {
        parent::setUp();

        IndustryData::flush();
        StructureBonuses::flush();
    }

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
            productResolver: fn (int $productId) => $this->productFor($productId)
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
            productResolver: fn (int $productId) => $this->product(RigScope::STRUCTURE, 1404, 65)
        );

        $job = $assigner->for($this->recipe(9003, 1404, IndustryActivity::MANUFACTURING));

        $this->assertSame(1, $job['structure_id']);
        $this->assertSame(0.0, $job['me_bonus']);
        $this->assertSame(1.0, $job['material_modifier']);
        $this->assertSame('uncovered', $job['source']);
    }

    public function test_a_refinery_runs_reactions_and_manufacturing_alike(): void
    {
        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Athanor', StructureTypes::ATHANOR, [
                    $this->rig(46486, 'Standup M-Set Composite Reactor ME I', ['me' => -2.0], [2718, 2714], 1.1),
                ], 1.1),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::REACTION_CHEMICAL, 428, 4)
        );

        // A refinery hosts the manufacturing plant as readily as a citadel or a
        // complex does, so a manufacturing job is offered there too.
        $manufacturing = $assigner->for($this->recipe(9004, 334, IndustryActivity::MANUFACTURING));

        $this->assertSame(1, $manufacturing['structure_id']);

        $reaction = $assigner->for($this->recipe(9005, 428, IndustryActivity::REACTIONS));

        $this->assertSame(1, $reaction['structure_id']);
        $this->assertSame(RigScope::REACTION_CHEMICAL, $reaction['scope']);
        // The rig's own 1.1 null-sec band multiplier, not the 1.9/2.1 of an
        // engineering rig.
        $this->assertSame(2.2, $reaction['me_bonus']);
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
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
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
            productResolver: fn (int $productId) => $this->product('general', 334, 17)
        );

        $job = $assigner->for($this->recipe(9006, 334, IndustryActivity::INVENTION));

        $this->assertSame('general', $job['scope']);
        $this->assertSame(21.0, $job['cost_bonus']);
        $this->assertSame(0.79, round($job['cost_modifier'], 2));
    }

    // ----------------------------------------------------------------------
    // The capability gate: fitted service modules decide, not structure types
    // ----------------------------------------------------------------------

    public function test_a_structure_is_offered_only_when_a_fitted_service_runs_the_job(): void
    {
        $this->seedCapability();

        $assigner = new RunAssigner(
            collect([
                // A citadel with a Standup Manufacturing Plant I fitted.
                $this->structure(1, 'Astrahus with a plant', StructureTypes::ASTRAHUS, [], 1.0, [175]),
                // A complex with nothing fitted in its service slots.
                $this->structure(2, 'Azbel with nothing fitted', StructureTypes::AZBEL, [], 2.1, []),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        );

        $job = $assigner->for($this->recipe(9010, 334, IndustryActivity::MANUFACTURING));

        // The citadel can run it and the complex cannot, whatever their sizes say.
        $this->assertSame(1, $job['structure_id']);
        $this->assertSame('Structure Basic Manufacturing', $job['service']);
    }

    public function test_capital_products_need_the_capital_shipyard_line(): void
    {
        $this->seedCapability();

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Sotiyo, plain plant', StructureTypes::SOTIYO, [], 2.1, [175]),
                $this->structure(2, 'Sotiyo, capital shipyard', StructureTypes::SOTIYO, [], 2.1, [176]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::CAP_SHIP, 485, 6)
        );

        $dreadnought = $assigner->for($this->recipe(9011, 485, IndustryActivity::MANUFACTURING));

        // Line 175 lists neither group 485 nor category 6, so only the shipyard
        // structure is able.
        $this->assertSame(2, $dreadnought['structure_id']);
        $this->assertSame('Structure Capital Shipyard', $dreadnought['service']);

        // The same structure pair for an ordinary product goes to the plain plant.
        $component = (new RunAssigner(
            collect([
                $this->structure(1, 'Sotiyo, plain plant', StructureTypes::SOTIYO, [], 2.1, [175]),
                $this->structure(2, 'Sotiyo, capital shipyard', StructureTypes::SOTIYO, [], 2.1, [176]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        ))->for($this->recipe(9012, 334, IndustryActivity::MANUFACTURING));

        $this->assertSame(1, $component['structure_id']);
    }

    public function test_reactions_are_refused_above_security_04(): void
    {
        $this->seedCapability();

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Tatara in highsec', StructureTypes::TATARA, [], 1.0, [182], 0.5),
                $this->structure(2, 'Tatara in nullsec', StructureTypes::TATARA, [], 1.0, [182], -0.3),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::REACTION_CHEMICAL, 428, 4)
        );

        $reaction = $assigner->for($this->recipe(9013, 428, IndustryActivity::REACTIONS));

        $this->assertSame(2, $reaction['structure_id']);
        $this->assertSame('Standup Composite Reactor', $reaction['service']);

        // The Tatara's own reaction time multiplier is applied to the run.
        $this->assertSame(0.75, $reaction['structure_bonus']['time']);
        $this->assertSame(0.75, round($reaction['time_modifier'], 2));
    }

    public function test_the_security_limit_comes_from_the_service_module(): void
    {
        $this->seedCapability();

        // 45537 Standup Composite Reactor I publishes onlineMaxSecurityClass = 1
        // (lowsec) and disallowInHighSec = 1; highsec starts at 0.5. The plain
        // manufacturing plant publishes neither, so it has no limit.
        $this->assertSame(0.4, ServiceModules::maxSecurity(45537));
        $this->assertNull(ServiceModules::maxSecurity(35878));

        $max = ServiceModules::maxSecurity(45537);

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Tatara at the limit', StructureTypes::TATARA, [], 1.0, [182], 0.4, [182 => $max]),
                $this->structure(2, 'Tatara in highsec', StructureTypes::TATARA, [], 1.0, [182], 0.5, [182 => $max]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::REACTION_CHEMICAL, 428, 4)
        );

        $reaction = $assigner->for($this->recipe(9016, 428, IndustryActivity::REACTIONS));

        $this->assertSame(1, $reaction['structure_id']);
    }

    public function test_capital_builds_need_a_system_the_shipyard_can_be_online_in(): void
    {
        $this->seedCapability();

        // 35881 Standup Capital Shipyard I carries the same pair of attributes the
        // reactors do, which is where "no capital ships in highsec" comes from.
        $this->assertSame(0.4, ServiceModules::maxSecurity(35881));

        $max = ServiceModules::maxSecurity(35881);

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Azbel in highsec', StructureTypes::AZBEL, [], 1.0, [176], 0.5, [176 => $max]),
                $this->structure(2, 'Azbel in lowsec', StructureTypes::AZBEL, [], 1.0, [176], 0.4, [176 => $max]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::CAP_SHIP, 485, 6)
        );

        $ship = $assigner->for($this->recipe(9017, 485, IndustryActivity::MANUFACTURING));

        $this->assertSame(2, $ship['structure_id']);
        $this->assertSame('Structure Capital Shipyard', $ship['service']);
    }

    public function test_the_structure_type_bonus_is_folded_into_the_run_modifiers(): void
    {
        DB::table('dgmTypeAttributes')->insert([
            ['typeID' => StructureTypes::RAITARU, 'attributeID' => 2600, 'valueInt' => null, 'valueFloat' => 0.99],
            ['typeID' => StructureTypes::RAITARU, 'attributeID' => 2601, 'valueInt' => null, 'valueFloat' => 0.97],
            ['typeID' => StructureTypes::RAITARU, 'attributeID' => 2602, 'valueInt' => null, 'valueFloat' => 0.85],
        ]);

        IndustryData::flush();

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Raitaru', StructureTypes::RAITARU, [], 2.1, [175]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        );

        $job = $assigner->for($this->recipe(9014, 334, IndustryActivity::MANUFACTURING));

        $this->assertSame(0.99, $job['structure_bonus']['material']);
        $this->assertSame(0.99, round($job['material_modifier'], 2));
        $this->assertSame(0.97, round($job['cost_modifier'], 2));
        $this->assertSame(0.85, round($job['time_modifier'], 2));
    }

    public function test_a_structure_type_that_publishes_no_bonus_reads_neutral(): void
    {
        $this->seedCapability();

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Astrahus', StructureTypes::ASTRAHUS, [], 1.0, [175]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        );

        $job = $assigner->for($this->recipe(9015, 334, IndustryActivity::MANUFACTURING));

        $this->assertSame(['material' => 1.0, 'cost' => 1.0, 'time' => 1.0], $job['structure_bonus']);
    }

    public function test_the_character_named_for_a_run_is_one_that_can_run_it(): void
    {
        $this->seedCapability();
        $this->linkCharacter(900, 'Capable Charlie', [3380 => 5, 3388 => 5]);
        $this->linkCharacter(901, 'Slow Sam', [3380 => 1]);

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Astrahus', StructureTypes::ASTRAHUS, [], 1.0, [175]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        );

        $job = $assigner->for($this->recipe(9016, 334, IndustryActivity::MANUFACTURING, [
            ['skill_id' => 3380, 'name' => 'Industry', 'level' => 5],
        ]));

        $this->assertSame(900, $job['character_id']);
        $this->assertSame('Capable Charlie', $job['character_name']);
        $this->assertTrue($job['character_qualified']);
        $this->assertSame([], $job['missing_skills']);

        // Industry 5 at 4% a level and Advanced Industry 5 at 3% a level, on a
        // structure that carries no time bonus of its own. CCP applies each skill
        // as a percent on the character's manufactureTimeMultiplier, so the two
        // stack as 0.80 × 0.85 rather than as one 35% cut.
        $this->assertSame(0.68, round($job['time_modifier'], 2));
    }

    public function test_a_run_nobody_qualifies_for_is_still_attributed_and_flagged(): void
    {
        $this->seedCapability();
        $this->linkCharacter(902, 'Untrained Uri', [3380 => 2, 3388 => 4]);

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Astrahus', StructureTypes::ASTRAHUS, [], 1.0, [175]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        );

        $job = $assigner->for($this->recipe(9017, 334, IndustryActivity::MANUFACTURING, [
            ['skill_id' => 3380, 'name' => 'Industry', 'level' => 5],
        ]));

        $this->assertSame(902, $job['character_id']);
        $this->assertFalse($job['character_qualified']);
        $this->assertSame(
            ['skill_id' => 3380, 'name' => 'Industry', 'required' => 5, 'have' => 2],
            $job['missing_skills'][0]
        );

        // The speed still counts: the plan says what this character would do to
        // the clock, and the flag says they cannot start the job yet.
        $this->assertSame(0.81, round($job['time_modifier'], 2));
    }

    public function test_a_stored_character_is_measured_against_the_recipe_too(): void
    {
        $this->seedCapability();
        $this->linkCharacter(903, 'Capable Charlie', [3380 => 5]);
        $this->linkCharacter(904, 'Untrained Uri', [3380 => 1]);

        DB::table(IndustryData::TABLE_RUNS)->insert([
            'project_id' => 9,
            'blueprint_type_id' => 9018,
            'activity_id' => IndustryActivity::MANUFACTURING,
            'product_type_id' => 9018,
            'runs' => 1,
            'structure_id' => 1,
            'character_id' => 904,
            'is_override' => 1,
        ]);

        $assigner = new RunAssigner(
            collect([
                $this->structure(1, 'Astrahus', StructureTypes::ASTRAHUS, [], 1.0, [175]),
            ]),
            productResolver: fn (int $productId) => $this->product(RigScope::ADV_COMPONENT, 334, 17)
        );

        $assigner->loadAssignments(9);

        $job = $assigner->for($this->recipe(9018, 334, IndustryActivity::MANUFACTURING, [
            ['skill_id' => 3380, 'name' => 'Industry', 'level' => 5],
        ]));

        $this->assertTrue($job['is_override']);
        $this->assertSame(904, $job['character_id']);
        $this->assertFalse($job['character_qualified']);
        $this->assertSame(
            ['skill_id' => 3380, 'name' => 'Industry', 'required' => 5, 'have' => 1],
            $job['missing_skills'][0]
        );
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * The assembly lines and service modules CCP publishes for the structures
     * these tests talk about.
     */
    private function seedCapability(): void
    {
        DB::table(IndustryData::TABLE_ASSEMBLY_LINES)->insertOrIgnore([
            [
                'assemblyLineID' => 175,
                'activityID' => IndustryActivity::MANUFACTURING,
                'name' => 'Structure Basic Manufacturing',
                'groupIDs' => json_encode([25, 26, 27, 334, 963, 1305]),
                'categoryIDs' => json_encode([2, 4, 5, 17, 23, 65]),
            ],
            [
                'assemblyLineID' => 176,
                'activityID' => IndustryActivity::MANUFACTURING,
                'name' => 'Structure Capital Shipyard',
                'groupIDs' => json_encode([485, 547, 883, 1538, 4594, 5120]),
                'categoryIDs' => json_encode([]),
            ],
            [
                'assemblyLineID' => 178,
                'activityID' => IndustryActivity::RESEARCH_TE,
                'name' => 'Structure Time Efficiency Research',
                'groupIDs' => null,
                'categoryIDs' => null,
            ],
            [
                'assemblyLineID' => 182,
                'activityID' => IndustryActivity::REACTIONS,
                'name' => 'Standup Composite Reactor',
                'groupIDs' => json_encode([428, 429, 4932]),
                'categoryIDs' => json_encode([]),
            ],
        ]);

        DB::table(IndustryData::TABLE_INSTALLATIONS)->insertOrIgnore([
            ['typeID' => 35878, 'assemblyLineIDs' => json_encode([175])],
            ['typeID' => 35881, 'assemblyLineIDs' => json_encode([176])],
            ['typeID' => 35891, 'assemblyLineIDs' => json_encode([178])],
            ['typeID' => 45537, 'assemblyLineIDs' => json_encode([182])],
        ]);

        // The Tatara publishes the reaction time multiplier; the Astrahus publishes
        // nothing, which is what the data says. The reactor and the capital shipyard
        // publish the security class their service module may be onlined in.
        DB::table('dgmTypeAttributes')->insertOrIgnore([
            ['typeID' => StructureTypes::TATARA, 'attributeID' => 2721, 'valueInt' => null, 'valueFloat' => 0.75],
            ['typeID' => 45537, 'attributeID' => 2581, 'valueInt' => null, 'valueFloat' => 1.0],
            ['typeID' => 45537, 'attributeID' => 1970, 'valueInt' => null, 'valueFloat' => 1.0],
            ['typeID' => 35881, 'attributeID' => 2581, 'valueInt' => null, 'valueFloat' => 1.0],
            ['typeID' => 35881, 'attributeID' => 1970, 'valueInt' => null, 'valueFloat' => 1.0],
        ]);

        IndustryData::flush();
        StructureBonuses::flush();
        ServiceModules::flush();
    }

    /**
     * Resolve a product the way the production path does, from the stub SDE rows.
     */
    private function productFor(int $productId): array
    {
        $row = DB::table('invTypes as t')
            ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
            ->leftJoin('invCategories as c', 'c.categoryID', '=', 'g.categoryID')
            ->where('t.typeID', $productId)
            ->first(['t.groupID', 'g.groupID', 'g.categoryID', 'g.groupName', 'c.categoryName', 't.techLevel']);

        if (!$row) {
            return ['scope' => 'general', 'groupID' => null, 'categoryID' => null];
        }

        return [
            'scope' => RigScope::tokenForProduct(
                (int) $row->groupID,
                $row->groupName,
                $row->categoryName,
                $row->techLevel
            ),
            'groupID' => (int) $row->groupID,
            'categoryID' => $row->categoryID !== null ? (int) $row->categoryID : null,
        ];
    }

    private function product(string $scope, ?int $groupId, ?int $categoryId): array
    {
        return ['scope' => $scope, 'groupID' => $groupId, 'categoryID' => $categoryId];
    }

    /**
     * A user with a linked character and that character's trained skills, in the
     * tables SeAT syncs, so the character half of an assignment is exercised
     * against the same shape the live read path uses.
     */
    private function linkCharacter(int $characterId, string $name, array $skills): void
    {
        if (! Schema::hasTable('refresh_tokens')) {
            Schema::create('refresh_tokens', function ($table) {
                $table->integer('user_id');
                $table->bigInteger('character_id')->primary();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        if (! Schema::hasTable('character_affiliations')) {
            Schema::create('character_affiliations', function ($table) {
                $table->bigInteger('character_id')->primary();
                $table->bigInteger('corporation_id');
                $table->bigInteger('alliance_id')->nullable();
            });
        }

        if (! Schema::hasTable('character_infos')) {
            Schema::create('character_infos', function ($table) {
                $table->bigInteger('character_id')->primary();
                $table->string('name');
            });
        }

        if (! Schema::hasTable('character_skills')) {
            Schema::create('character_skills', function ($table) {
                $table->bigInteger('character_id');
                $table->integer('skill_id');
                $table->integer('trained_skill_level');
                $table->primary(['character_id', 'skill_id']);
            });
        }

        DB::table('refresh_tokens')->insertOrIgnore([
            'user_id' => 1,
            'character_id' => $characterId,
            'deleted_at' => null,
        ]);

        DB::table('character_affiliations')->insertOrIgnore([
            'character_id' => $characterId,
            'corporation_id' => 98690019,
            'alliance_id' => 99011279,
        ]);

        DB::table('character_infos')->insertOrIgnore([
            'character_id' => $characterId,
            'name' => $name,
        ]);

        foreach ($skills as $skillId => $level) {
            DB::table('character_skills')->insertOrIgnore([
                'character_id' => $characterId,
                'skill_id' => $skillId,
                'trained_skill_level' => $level,
            ]);
        }

        Auth::setUser(new GenericUser(['id' => 1]));
    }

    private function structure(
        int $structureId,
        string $name,
        int $typeId,
        array $rigs,
        float $multiplier = 2.1,
        array $lineIds = [],
        ?float $security = -0.5,
        array $lineMaxSecurity = []
    ): array {
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
            'security' => $security,
            'lines' => $this->lines($lineIds, $lineMaxSecurity),
            'fit' => [
                'band' => $security !== null && $security > 0.0 ? 'highsec' : 'nullsec',
                'multiplier' => $multiplier,
                'rigs' => $rigs,
                'me_bonus' => $best['me'],
                'te_bonus' => $best['te'],
                'cost_bonus' => $best['cost'],
                'source' => $rigs ? 'fitted' : 'base',
            ],
        ];
    }

    /**
     * The fitted assembly lines, in the shape StructureServices hands them over.
     */
    private function lines(array $lineIds, array $maxSecurity = []): array
    {
        $out = [];

        foreach ($lineIds as $lineId) {
            $row = DB::table(IndustryData::TABLE_ASSEMBLY_LINES)
                ->where('assemblyLineID', $lineId)
                ->first();

            if (!$row) {
                continue;
            }

            $line = [
                'assemblyLineID' => (int) $row->assemblyLineID,
                'activityID' => (int) $row->activityID,
                'name' => $row->name,
                'groupIDs' => AssemblyLines::decode($row->groupIDs),
                'categoryIDs' => AssemblyLines::decode($row->categoryIDs),
            ];

            if (array_key_exists($lineId, $maxSecurity)) {
                $line['max_security'] = $maxSecurity[$lineId];
            }

            $out[$lineId] = $line;
        }

        return $out;
    }

    private function rig(int $typeId, string $name, array $bonuses, array $attributes, float $multiplier = 2.1): array
    {
        $effectiveByLabel = [];

        foreach ($bonuses as $label => $raw) {
            $effectiveByLabel[$label] = round(abs($raw) * $multiplier, 2);
        }

        $primary = array_key_first($bonuses);

        return [
            'type_id' => $typeId,
            'name' => $name,
            'bonus' => $primary,
            'raw' => round($bonuses[$primary], 2),
            'multiplier' => $multiplier,
            'effective' => $effectiveByLabel[$primary],
            'bonuses' => $bonuses,
            'effective_by_label' => $effectiveByLabel,
            'attributes' => $attributes,
            'scopes' => [],
        ];
    }

    /**
     * A recipe in the shape ProductionCalculator::recipe() returns.
     *
     * @param  array  $skills  list of ['skill_id' => int, 'name' => string, 'level' => int]
     */
    private function recipe(int $bp, int $groupId, int $activityId, array $skills = []): array
    {
        // Seed the stub SDE so the scope resolver can classify the product.
        DB::table('invCategories')->insertOrIgnore([
            ['categoryID' => 4, 'categoryName' => 'Material'],
            ['categoryID' => 6, 'categoryName' => 'Ship'],
            ['categoryID' => 17, 'categoryName' => 'Commodity'],
            ['categoryID' => 24, 'categoryName' => 'Reaction'],
            ['categoryID' => 65, 'categoryName' => 'Structure'],
        ]);

        $groups = [
            334 => ['categoryID' => 17, 'groupName' => 'Construction Components'],
            324 => ['categoryID' => 6, 'groupName' => 'Assault Frigate'],
            428 => ['categoryID' => 4, 'groupName' => 'Intermediate Materials'],
            485 => ['categoryID' => 6, 'groupName' => 'Dreadnaught'],
            1404 => ['categoryID' => 65, 'groupName' => 'Engineering Complex'],
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
            'skills' => $skills,
        ];
    }
}
