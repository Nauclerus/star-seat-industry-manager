<?php

namespace IndustryManager\Tests\Feature;

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
        $zip = (string) (getenv('IM_REAL_SDE_ZIP') ?: '');

        if ($zip === '' || ! is_readable($zip)) {
            $this->markTestSkipped('Set IM_REAL_SDE_ZIP to a CCP JSONL archive to run this test.');
        }

        $dir = storage_path('sde/' . self::BUILD);
        mkdir($dir, 0755, true);

        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zip) === true);

        foreach (['blueprints.jsonl', 'planetSchematics.jsonl', 'dogmaEffects.jsonl'] as $name) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $entry = $archive->statIndex($i)['name'];

                if (basename($entry) === $name) {
                    $archive->extractTo($dir, $entry);
                }
            }
        }

        $archive->close();

        $counts = (new CcpJsonlSource(null, self::BUILD))->import();

        $this->assertSame(19138, $counts[IndustryData::TABLE_ACTIVITY]);
        $this->assertSame(36500, $counts[IndustryData::TABLE_MATERIALS]);
        $this->assertSame(6330, $counts[IndustryData::TABLE_PRODUCTS]);
        $this->assertSame(22398, $counts[IndustryData::TABLE_SKILLS]);
        $this->assertSame(1353, $counts[IndustryData::TABLE_PROBABILITIES]);
        $this->assertSame(68, $counts[IndustryData::TABLE_PI_SCHEMATICS]);
        $this->assertSame(203, $counts[IndustryData::TABLE_PI_TYPEMAP]);
        $this->assertSame(3422, $counts[IndustryData::TABLE_EFFECTS]);
    }
}
