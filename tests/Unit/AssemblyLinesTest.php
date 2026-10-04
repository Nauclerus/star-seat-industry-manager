<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\AssemblyLines;
use IndustryManager\Helpers\IndustryActivity;
use PHPUnit\Framework\TestCase;

/**
 * Assembly-line matching. The lines here are the ones CCP publishes in
 * industryAssemblyLines.jsonl (build 3569502), copied exactly so the rules the
 * capability gate relies on are pinned against the data rather than remembered.
 */
class AssemblyLinesTest extends TestCase
{
    /** Structure Basic Manufacturing — the Standup Manufacturing Plant I. */
    private const LINE_175 = [
        'assemblyLineID' => 175,
        'activityID' => IndustryActivity::MANUFACTURING,
        'name' => 'Structure Basic Manufacturing',
        'groupIDs' => [25, 26, 27, 31, 420, 963, 1305],
        'categoryIDs' => [2, 4, 5, 7, 8, 17, 18, 20, 22, 23, 32, 39, 40, 46, 65, 66, 87],
    ];

    /** Structure Capital Shipyard. */
    private const LINE_176 = [
        'assemblyLineID' => 176,
        'activityID' => IndustryActivity::MANUFACTURING,
        'name' => 'Structure Capital Shipyard',
        'groupIDs' => [485, 547, 883, 1538, 4594, 5120],
        'categoryIDs' => [],
    ];

    /** A lab line: no group and no category list at all. */
    private const LINE_179 = [
        'assemblyLineID' => 179,
        'activityID' => IndustryActivity::RESEARCH_ME,
        'name' => 'Structure Material Efficiency Research',
        'groupIDs' => [],
        'categoryIDs' => [],
    ];

    public function test_decode_reads_the_json_columns(): void
    {
        $this->assertSame([175, 176], AssemblyLines::decode('[175,176]'));
        $this->assertSame([175], AssemblyLines::decode('["175"]'));
        $this->assertSame([], AssemblyLines::decode(null));
        $this->assertSame([], AssemblyLines::decode(''));
        $this->assertSame([], AssemblyLines::decode('not json'));
        $this->assertSame([], AssemblyLines::decode('{"a":1}'));
    }

    public function test_a_line_without_lists_accepts_any_product(): void
    {
        $this->assertTrue(AssemblyLines::accepts(self::LINE_179, 485, 6));
        $this->assertTrue(AssemblyLines::accepts(self::LINE_179, null, null));
    }

    public function test_a_group_specific_line_stays_group_specific_when_nothing_matches(): void
    {
        // Group 999 in a category the line does list would be admitted by the
        // category, but with no category to match on the group list decides.
        $this->assertFalse(AssemblyLines::accepts(self::LINE_176, 999, null));
        $this->assertTrue(AssemblyLines::accepts(self::LINE_176, 485, null));
    }

    public function test_capital_ships_are_not_made_by_the_plain_manufacturing_plant(): void
    {
        // Line 175 lists neither the capital groups nor the Ship category.
        foreach ([485, 547, 883, 1538, 4594, 5120] as $group) {
            $this->assertFalse(
                AssemblyLines::accepts(self::LINE_175, $group, 6),
                'group ' . $group . ' must not match line 175'
            );
        }

        // ...and 176 is the line that does.
        foreach ([485, 547, 883, 1538, 4594, 5120] as $group) {
            $this->assertTrue(
                AssemblyLines::accepts(self::LINE_176, $group, 6),
                'group ' . $group . ' must match line 176'
            );
        }
    }

    public function test_ordinary_products_match_the_plain_manufacturing_plant(): void
    {
        $this->assertTrue(AssemblyLines::accepts(self::LINE_175, 334, 17));   // component
        $this->assertTrue(AssemblyLines::accepts(self::LINE_175, 25, 6));     // frigate group
        $this->assertTrue(AssemblyLines::accepts(self::LINE_175, 963, 6));    // strategic cruiser
        $this->assertTrue(AssemblyLines::accepts(self::LINE_175, 536, 65));   // structure component
    }

    public function test_a_product_outside_both_lists_is_refused(): void
    {
        $this->assertFalse(AssemblyLines::accepts(self::LINE_176, 334, 17));
        $this->assertFalse(AssemblyLines::accepts(self::LINE_175, 999, 999));
    }

    public function test_find_requires_the_activity_to_match_as_well_as_the_product(): void
    {
        $lines = [175 => self::LINE_175, 176 => self::LINE_176, 179 => self::LINE_179];

        $this->assertSame(176, AssemblyLines::find($lines, IndustryActivity::MANUFACTURING, 485, 6)['assemblyLineID']);
        $this->assertSame(175, AssemblyLines::find($lines, IndustryActivity::MANUFACTURING, 334, 17)['assemblyLineID']);

        // A research job matches the lab line, not either manufacturing line.
        $this->assertSame(179, AssemblyLines::find($lines, IndustryActivity::RESEARCH_ME, 485, 6)['assemblyLineID']);

        // Copying has no line fitted here at all.
        $this->assertNull(AssemblyLines::find($lines, IndustryActivity::COPYING, 334, 17));
        $this->assertNull(AssemblyLines::find([], IndustryActivity::MANUFACTURING, 334, 17));
    }

    public function test_activities_collapses_fitted_lines_to_one_entry_per_activity(): void
    {
        $lines = [175 => self::LINE_175, 176 => self::LINE_176, 179 => self::LINE_179];
        $activities = AssemblyLines::activities($lines);

        $this->assertSame(
            [IndustryActivity::MANUFACTURING, IndustryActivity::RESEARCH_ME],
            array_keys($activities)
        );
        $this->assertSame(175, $activities[IndustryActivity::MANUFACTURING]['assemblyLineID']);
    }
}
