<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Services\RecipeSources\CcpJsonlSource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Which files an import may reuse from disk.
 *
 * The plugin reuses whatever SeAT core already extracted for the same build, but
 * core extracts only what core imports — so the check has to be against the
 * plugin's own list, not against "did we find something".
 */
class ArchiveFileSetTest extends TestCase
{
    public function test_a_directory_that_only_satisfies_core_is_not_reused(): void
    {
        // What core's own SDE import leaves behind for a build.
        $coreOnly = [
            'blueprints.jsonl' => '/sde/3569502/blueprints.jsonl',
            'planetSchematics.jsonl' => '/sde/3569502/planetSchematics.jsonl',
            'dogmaEffects.jsonl' => '/sde/3569502/dogmaEffects.jsonl',
            'industryAssemblyLines.jsonl' => null,
            'industryInstallationTypes.jsonl' => null,
        ];

        $this->assertFalse(CcpJsonlSource::isComplete($coreOnly));
    }

    public function test_a_full_set_for_the_build_is_reused(): void
    {
        $complete = [];

        foreach (CcpJsonlSource::ARCHIVE_FILES as $basename) {
            $complete[$basename] = '/sde/3569502/' . $basename;
        }

        $this->assertTrue(CcpJsonlSource::isComplete($complete));
    }

    public function test_every_file_read_has_a_method_to_read_it(): void
    {
        $importers = (new ReflectionClass(CcpJsonlSource::class))
            ->getConstant('IMPORTERS');

        foreach (CcpJsonlSource::ARCHIVE_FILES as $basename) {
            $this->assertArrayHasKey(
                $basename,
                (array) $importers,
                $basename . ' is located and extracted but nothing imports it.'
            );
        }
    }
}
