<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\AssemblyLines;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\RecipeSources\CcpJsonlSource;
use IndustryManager\Tests\TestCase;

/**
 * The CCP parser against CCP's real archive, checked at the counts the community
 * dumps are independently known to produce for the same build. That is what
 * makes "CCP is the source, Fuzzwork is the fallback" a claim about equivalence
 * rather than preference.
 *
 * Needs the archive locally: IM_REAL_SDE_ZIP=/path/to/eve-online-static-data-<build>-jsonl.zip.
 * Without it the test skips, so CI never downloads 100 MB.
 */
class RealArchiveImportTest extends TestCase
{
    private const BUILD = '3569502';

    public function test_ccps_archive_imports_at_the_known_counts(): void
    {
        $counts = $this->importArchive();

        $this->assertSame(19138, $counts[IndustryData::TABLE_ACTIVITY]);
        $this->assertSame(36500, $counts[IndustryData::TABLE_MATERIALS]);
        $this->assertSame(6330, $counts[IndustryData::TABLE_PRODUCTS]);
        $this->assertSame(22398, $counts[IndustryData::TABLE_SKILLS]);
        $this->assertSame(1353, $counts[IndustryData::TABLE_PROBABILITIES]);
        $this->assertSame(68, $counts[IndustryData::TABLE_PI_SCHEMATICS]);
        $this->assertSame(203, $counts[IndustryData::TABLE_PI_TYPEMAP]);
        $this->assertSame(3422, $counts[IndustryData::TABLE_EFFECTS]);
    }

    public function test_ccps_archive_imports_the_capability_tables(): void
    {
        $counts = $this->importArchive();

        $this->assertSame(146, $counts[IndustryData::TABLE_ASSEMBLY_LINES]);
        $this->assertSame(102, $counts[IndustryData::TABLE_INSTALLATIONS]);

        // The service modules that decide what a structure can actually run.
        $this->assertSame([175], $this->linesFor(35878));   // Standup Manufacturing Plant I
        $this->assertSame([176], $this->linesFor(35881));   // Standup Capital Shipyard I
        $this->assertSame([176, 177], $this->linesFor(35877)); // Standup Supercapital Shipyard I
        $this->assertSame([182], $this->linesFor(45537));   // Standup Composite Reactor I
        $this->assertSame([183], $this->linesFor(45538));   // Standup Polymer Reactor I
        $this->assertSame([184], $this->linesFor(45539));   // Standup Biochemical Reactor I

        // Reprocessing is not an industry activity, so CCP gives it no assembly
        // line at all. The drug lab does have one (booster manufacturing), but the
        // module is unpublished, so no structure a player can fit reaches it.
        $this->assertSame([], $this->linesFor(35899));
        $this->assertSame([37], $this->linesFor(35880));

        // Service modules that are fitted in structures but provide no industry
        // activity: moon drilling, cloning, the FLEX slot, and the fitting modules
        // that only let other services be fitted.
        foreach ([45009, 82941, 35894, 35965, 47347, 35963, 47344] as $typeId) {
            $this->assertSame([], $this->linesFor($typeId), 'type ' . $typeId);
        }

        // The plain manufacturing plant is the line that excludes capitals, which
        // is the rule the capability gate depends on.
        $plant = AssemblyLines::decode(
            DB::table(IndustryData::TABLE_ASSEMBLY_LINES)
                ->where('assemblyLineID', 175)
                ->value('groupIDs')
        );

        foreach ([485, 547, 883, 1538, 4594, 5120] as $capital) {
            $this->assertNotContains($capital, $plant);
        }

        $this->assertContains(963, $plant);   // Strategic Cruiser
        $this->assertContains(1305, $plant);  // Tactical Destroyer
    }

    public function test_ccps_archive_publishes_a_duration_for_every_activity(): void
    {
        $this->importArchive();

        // Blueprint 681: 600 s to build, and CCP's own durations for the other jobs
        // on it. A copy is 80% of the build time and a research job already carries
        // the blueprint's rank multiplier, so the calculator has no duration math of
        // its own beyond the blueprint's TE levels.
        $this->assertSame(600, $this->durationFor(681, IndustryActivity::MANUFACTURING));
        $this->assertSame(480, $this->durationFor(681, IndustryActivity::COPYING));
        $this->assertSame(210, $this->durationFor(681, IndustryActivity::RESEARCH_ME));
        $this->assertSame(210, $this->durationFor(681, IndustryActivity::RESEARCH_TE));
    }

    /**
     * Extract the files the plugin reads and run the real import over them.
     */
    private function importArchive(): array
    {
        $zip = (string) (getenv('IM_REAL_SDE_ZIP') ?: '');

        if ($zip === '' || ! is_readable($zip)) {
            $this->markTestSkipped('Set IM_REAL_SDE_ZIP to a CCP JSONL archive to run this test.');
        }

        $dir = storage_path('sde/' . self::BUILD);
        mkdir($dir, 0755, true);

        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zip) === true);

        foreach (CcpJsonlSource::ARCHIVE_FILES as $name) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $entry = $archive->statIndex($i)['name'];

                if (basename($entry) === $name) {
                    $archive->extractTo($dir, $entry);
                }
            }
        }

        $archive->close();

        return (new CcpJsonlSource(null, self::BUILD))->import();
    }

    /**
     * The assembly lines CCP attaches to a service module type.
     */
    private function linesFor(int $typeId): array
    {
        $json = DB::table(IndustryData::TABLE_INSTALLATIONS)
            ->where('typeID', $typeId)
            ->value('assemblyLineIDs');

        return AssemblyLines::decode($json);
    }

    /**
     * The duration imported for one blueprint and activity.
     */
    private function durationFor(int $blueprintId, int $activityId): ?int
    {
        $time = DB::table(IndustryData::TABLE_ACTIVITY)
            ->where('typeID', $blueprintId)
            ->where('activityID', $activityId)
            ->value('time');

        return $time === null ? null : (int) $time;
    }
}
