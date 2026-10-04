<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\StructureBonuses;
use IndustryManager\Helpers\StructureTypes;
use PHPUnit\Framework\TestCase;

/**
 * Which activity each structure-wide attribute feeds, and what happens when the
 * attributes cannot be read.
 *
 * The mapping is CCP's, from industryModifierSources.jsonl (build 3569502): the
 * engineering complexes publish material/cost/time for manufacturing but only
 * cost/time for the lab activities, and the Tatara publishes a reaction time
 * multiplier and nothing else.
 */
class StructureBonusesTest extends TestCase
{
    protected function tearDown(): void
    {
        StructureBonuses::flush();
    }

    public function test_the_attributes_are_the_ones_ccp_names(): void
    {
        $this->assertSame(2600, StructureBonuses::MATERIAL_ATTRIBUTE);   // strEngMatBonus
        $this->assertSame(2601, StructureBonuses::COST_ATTRIBUTE);        // strEngCostBonus
        $this->assertSame(2602, StructureBonuses::TIME_ATTRIBUTE);        // strEngTimeBonus
        $this->assertSame(2721, StructureBonuses::REACTION_TIME_ATTRIBUTE); // strReactionTimeMultiplier
    }

    public function test_manufacturing_is_the_only_activity_that_gets_a_material_bonus(): void
    {
        $manufacturing = StructureBonuses::ACTIVITY_ATTRIBUTES[IndustryActivity::MANUFACTURING];

        $this->assertSame(
            ['material' => 2600, 'cost' => 2601, 'time' => 2602],
            $manufacturing
        );

        foreach ([IndustryActivity::COPYING, IndustryActivity::INVENTION,
                  IndustryActivity::RESEARCH_ME, IndustryActivity::RESEARCH_TE] as $activityId) {
            $attributes = StructureBonuses::ACTIVITY_ATTRIBUTES[$activityId];

            $this->assertArrayNotHasKey('material', $attributes, IndustryActivity::name($activityId));
            $this->assertSame(2601, $attributes['cost']);
            $this->assertSame(2602, $attributes['time']);
        }
    }

    public function test_reactions_are_fed_by_their_own_attribute(): void
    {
        $this->assertSame(
            ['time' => 2721],
            StructureBonuses::ACTIVITY_ATTRIBUTES[IndustryActivity::REACTIONS]
        );
    }

    public function test_an_unknown_activity_gets_nothing(): void
    {
        $this->assertArrayNotHasKey(99, StructureBonuses::ACTIVITY_ATTRIBUTES);
    }

    public function test_unreadable_attributes_read_as_no_bonus(): void
    {
        // No database in a unit test: the reader must degrade to neutral rather
        // than invent a bonus or blow up the calculator.
        $bonus = StructureBonuses::for(StructureTypes::SOTIYO, IndustryActivity::MANUFACTURING);

        $this->assertSame(['material' => 1.0, 'cost' => 1.0, 'time' => 1.0], $bonus);
        $this->assertFalse(StructureBonuses::publishesAny(StructureTypes::SOTIYO));
    }

    public function test_an_activity_without_attributes_reads_as_no_bonus(): void
    {
        StructureBonuses::flush();

        $this->assertSame(
            ['material' => 1.0, 'cost' => 1.0, 'time' => 1.0],
            StructureBonuses::for(StructureTypes::KEEPSTAR, 99)
        );
    }
}
