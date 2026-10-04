<?php

namespace IndustryManager\Services\RecipeSources;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;

/**
 * CCP's official JSONL SDE — the authoritative source.
 *
 * Reads the two files the recipe layer needs:
 *
 *   blueprints.jsonl       -> recipes / materials / products / skills / probabilities
 *   planetSchematics.jsonl  -> pi_schematics + pi_types (types are embedded per
 *                              schematic; CCP has no separate type-map file)
 *
 * Files are reused from SeAT's own SDE extraction when it is present on disk —
 * that is a read of files, not a write to core tables — and only downloaded when
 * they are not. CCP publishes the whole SDE as one zip, so a download is ~99 MB
 * and only these two entries are extracted.
 *
 * CCP's `latest` alias only works at the top-level path, not under
 * `tranquility/`; the build-numbered form is what actually resolves. The
 * redirect is followed so both work.
 *
 * Writes only to the plugin's own tables.
 */
class CcpJsonlSource implements RecipeSource
{
    private const CCP_LATEST_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';

    /** CCP activity name -> the activityID the rest of the plugin filters on. */
    private const ACTIVITY_MAP = [
        'manufacturing' => IndustryActivity::MANUFACTURING,
        'research_time' => IndustryActivity::RESEARCH_TE,
        'research_material' => IndustryActivity::RESEARCH_ME,
        'copying' => IndustryActivity::COPYING,
        'invention' => IndustryActivity::INVENTION,
        'reaction' => IndustryActivity::REACTIONS,
    ];

    /** basename => the method that reads it. */
    private const IMPORTERS = [
        'blueprints.jsonl' => 'importBlueprints',
        'planetSchematics.jsonl' => 'importSchematics',
        'dogmaEffects.jsonl' => 'importEffects',
        'industryAssemblyLines.jsonl' => 'importAssemblyLines',
        'industryInstallationTypes.jsonl' => 'importInstallations',
    ];

    /** The archive entries this plugin reads, keyed by basename. */
    public const ARCHIVE_FILES = [
        'blueprints.jsonl',
        'planetSchematics.jsonl',
        'dogmaEffects.jsonl',
        'industryAssemblyLines.jsonl',
        'industryInstallationTypes.jsonl',
    ];

    private string $work;

    private ?string $pinned = null;

    /** @var array{build:?string, url:?string}|null */
    private ?array $latest = null;

    private string $version = 'unknown';

    /**
     * @param  string|null  $workDir  Where downloaded files are kept. Defaults to
     *                                storage/sde/industry-manager/.
     * @param  string|null  $build    Pin the SDE build instead of asking CCP which
     *                                one is current. Used by the tests.
     */
    public function __construct(?string $workDir = null, ?string $build = null)
    {
        $this->work = $workDir ?? storage_path('sde/industry-manager/');
        $this->pinned = $build;
    }

    public function name(): string
    {
        return 'ccp-jsonl';
    }

    public function label(): string
    {
        return 'CCP official JSONL SDE';
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Can this source deliver its files on this install?
     *
     * CCP publishes the archive itself, so the only real requirement is the
     * zip extension used to extract it. The import reuses the files SeAT core
     * already extracted whenever they are on disk, and downloads otherwise.
     */
    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public function import(): array
    {
        if (! File::exists($this->work)) {
            File::makeDirectory($this->work, 0755, true);
        }

        $files = $this->locateFiles();

        if (! array_filter($files)) {
            throw new \RuntimeException('Could not find or download CCP SDE files (' . implode(' / ', self::ARCHIVE_FILES) . ').');
        }

        $rows = [];

        // One clear for the whole run, so a partial file set cannot leave stale rows.
        $this->truncate();

        foreach (self::ARCHIVE_FILES as $basename) {
            if (! $files[$basename]) {
                continue;
            }

            $method = self::IMPORTERS[$basename];

            $rows = array_merge($rows, $this->$method($files[$basename]));
        }

        return $rows;
    }

    /**
     * @return array<string, ?string>  basename => path, keyed by self::ARCHIVE_FILES
     */
    /**
     * Files for the build CCP is publishing right now.
     *
     * Anything already on disk for that build is reused — SeAT core extracts the
     * same archive, and so may a previous run of this import. Files from an older
     * build are never reused silently, because that is what would leave the
     * recipe data stale after a patch.
     *
     * @return array<string, ?string>
     */
    private function locateFiles(): array
    {
        $onDisk = [];

        foreach (self::ARCHIVE_FILES as $basename) {
            $onDisk[$basename] = $this->findUnderStorage($basename);
        }

        $diskBuild = null;

        foreach ($onDisk as $path) {
            if ($path !== null && ($diskBuild = $this->buildFromPath($path)) !== null) {
                break;
            }
        }
        $current = $this->pinned ?? $this->latest()['build'];

        if ($current === null) {
            // CCP cannot be reached: the newest files on disk are the best there is.
            $this->version = $diskBuild ?? 'unknown';

            return $onDisk;
        }

        $this->version = $current;

        if ($diskBuild === $current) {
            return $onDisk;
        }

        return $this->downloadAndExtract($current);
    }

    /**
     * What CCP's `latest` alias currently points at: the build number and the
     * archive behind it.
     *
     * The alias answers with a 302 whose headers carry both
     * (`x-sde-build-number` and `location`). The archive itself comes from object
     * storage and does not repeat them, so the redirect must not be followed
     * here. Resolved once per import.
     *
     * @return array{build:?string, url:?string}
     */
    private function latest(): array
    {
        if ($this->latest !== null) {
            return $this->latest;
        }

        $this->latest = ['build' => null, 'url' => null];

        try {
            $client = new Client(['timeout' => 30, 'connect_timeout' => 10]);

            $res = $client->request('HEAD', self::CCP_LATEST_URL, [
                'allow_redirects' => false,
                'headers' => ['User-Agent' => 'IndustryManager-SeAT-plugin'],
            ]);

            $build = (int) $res->getHeaderLine('x-sde-build-number');
            $location = trim((string) $res->getHeaderLine('location'));

            if ($build) {
                $this->latest['build'] = (string) $build;
            } elseif (preg_match('/static-data-(\d+)-jsonl\.zip/', $location, $m)) {
                $this->latest['build'] = $m[1];
            }

            if ($location !== '') {
                $this->latest['url'] = $location;
            }
        } catch (\Throwable $e) {
            // Unreachable; the caller decides what to fall back to.
        }

        return $this->latest;
    }

    /**
     * Newest file with the given basename under SeAT's SDE storage.
     */
    private function findUnderStorage(string $basename): ?string
    {
        $root = storage_path('sde');

        if (! File::isDirectory($root)) {
            return null;
        }

        $best = null;
        $bestMtime = -1;

        foreach (File::allFiles($root) as $file) {
            if (strcasecmp($file->getFilename(), $basename) === 0 && $file->getMTime() > $bestMtime) {
                $bestMtime = $file->getMTime();
                $best = $file->getPathname();
            }
        }

        return $best;
    }

    /**
     * SeAT extracts the JSONL under storage/sde/<build>/, so the directory name
     * is the build number.
     */
    private function buildFromPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $parent = basename(dirname($path));

        return ctype_digit($parent) ? $parent : null;
    }

    /**
     * Fetch CCP's archive and keep the files this plugin needs, under a
     * directory named for the build so a later run can recognise and reuse them.
     *
     * @return array<string, ?string>
     */
    private function downloadAndExtract(string $build): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException("The PHP zip extension is required to extract CCP's SDE but is not loaded.");
        }

        $url = $this->latest()['url'] ?? self::CCP_LATEST_URL;

        // The directory is named for the archive actually fetched, in case the
        // header and the redirect target ever disagree.
        if (preg_match('/static-data-(\d+)-jsonl\.zip/', $url, $m)) {
            $build = $m[1];
        }

        $this->version = $build;

        $dir = $this->work . $build . '/';

        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $client = new Client(['timeout' => 1200, 'connect_timeout' => 30]);
        $zipPath = $dir . 'ccp-sde.zip';

        $res = $client->request('GET', $url, [
            'sink' => $zipPath,
            'headers' => ['User-Agent' => 'IndustryManager-SeAT-plugin'],
        ]);

        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException('Download failed: HTTP ' . $res->getStatusCode());
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open the downloaded zip.');
        }

        $found = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i)['name'];

            foreach (self::ARCHIVE_FILES as $w) {
                if (strcasecmp(basename($entry), $w) === 0) {
                    $zip->extractTo($dir, $entry);
                    $found[$w] = $dir . basename($entry);
                }
            }
        }

        $zip->close();

        // The archive is ~100 MB and the extracted files are what the import reads.
        @unlink($zipPath);

        $paths = [];

        foreach (self::ARCHIVE_FILES as $w) {
            $paths[$w] = $found[$w] ?? null;
        }

        return $paths;
    }

    private function importBlueprints(string $path): array
    {
        $counts = array_fill_keys(IndustryData::TABLES, 0);
        $buffer = array_fill_keys(IndustryData::TABLES, []);

        $flush = function (bool $force = false) use (&$buffer, &$counts) {
            foreach ($buffer as $table => $rows) {
                if (count($rows) >= 1000 || ($force && $rows)) {
                    DB::table($table)->insert($rows);
                    $counts[$table] += count($rows);
                    $buffer[$table] = [];
                }
            }
        };

        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $bp = json_decode($line, true);

            if (! is_array($bp)) {
                continue;
            }

            $bpId = (int) ($bp['_key'] ?? $bp['blueprintTypeID'] ?? 0);

            if (! $bpId || ! is_array($bp['activities'] ?? null)) {
                continue;
            }

            foreach ($bp['activities'] as $activityName => $activity) {
                $activityId = self::ACTIVITY_MAP[$activityName] ?? null;

                if ($activityId === null || ! is_array($activity)) {
                    continue;
                }

                $buffer[IndustryData::TABLE_ACTIVITY][] = [
                    'typeID' => $bpId,
                    'activityID' => $activityId,
                    'time' => (int) ($activity['time'] ?? 0),
                ];

                foreach (($activity['materials'] ?? []) as $material) {
                    $buffer[IndustryData::TABLE_MATERIALS][] = [
                        'typeID' => $bpId,
                        'activityID' => $activityId,
                        'materialTypeID' => (int) ($material['typeID'] ?? 0),
                        'quantity' => (int) ($material['quantity'] ?? 0),
                    ];
                }

                foreach (($activity['products'] ?? []) as $product) {
                    $productId = (int) ($product['typeID'] ?? 0);

                    $buffer[IndustryData::TABLE_PRODUCTS][] = [
                        'typeID' => $bpId,
                        'activityID' => $activityId,
                        'productTypeID' => $productId,
                        'quantity' => (int) ($product['quantity'] ?? 1),
                    ];

                    if (isset($product['probability'])) {
                        $buffer[IndustryData::TABLE_PROBABILITIES][] = [
                            'typeID' => $bpId,
                            'activityID' => $activityId,
                            'productTypeID' => $productId,
                            'probability' => (float) $product['probability'],
                        ];
                    }
                }

                foreach (($activity['skills'] ?? []) as $skill) {
                    $buffer[IndustryData::TABLE_SKILLS][] = [
                        'typeID' => $bpId,
                        'activityID' => $activityId,
                        'skillID' => (int) ($skill['typeID'] ?? 0),
                        'level' => (int) ($skill['level'] ?? 0),
                    ];
                }
            }

            $flush();
        }

        $flush(true);
        fclose($handle);

        return $counts;
    }

    private function importSchematics(string $path): array
    {
        $counts = [
            IndustryData::TABLE_PI_SCHEMATICS => 0,
            IndustryData::TABLE_PI_TYPEMAP => 0,
        ];

        $schematicBuffer = [];
        $typeBuffer = [];

        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $schematic = json_decode($line, true);

            if (! is_array($schematic)) {
                continue;
            }

            $schematicId = (int) ($schematic['_key'] ?? 0);

            if (! $schematicId) {
                continue;
            }

            $name = $schematic['name']['en'] ?? ($schematic['schematicName'] ?? ('Schematic #' . $schematicId));

            $schematicBuffer[] = [
                'schematicID' => $schematicId,
                'schematicName' => mb_substr((string) $name, 0, 255),
                'cycleTime' => (int) ($schematic['cycleTime'] ?? 0),
            ];

            foreach (($schematic['types'] ?? []) as $type) {
                $typeId = (int) ($type['_key'] ?? $type['typeID'] ?? 0);

                if (! $typeId) {
                    continue;
                }

                $typeBuffer[] = [
                    'schematicID' => $schematicId,
                    'typeID' => $typeId,
                    'quantity' => (int) ($type['quantity'] ?? 0),
                    'isInput' => ! empty($type['isInput']) ? 1 : 0,
                ];
            }

            if (count($schematicBuffer) >= 500) {
                DB::table(IndustryData::TABLE_PI_SCHEMATICS)->insert($schematicBuffer);
                $counts[IndustryData::TABLE_PI_SCHEMATICS] += count($schematicBuffer);
                $schematicBuffer = [];
            }

            if (count($typeBuffer) >= 500) {
                DB::table(IndustryData::TABLE_PI_TYPEMAP)->insert($typeBuffer);
                $counts[IndustryData::TABLE_PI_TYPEMAP] += count($typeBuffer);
                $typeBuffer = [];
            }
        }

        if ($schematicBuffer) {
            DB::table(IndustryData::TABLE_PI_SCHEMATICS)->insert($schematicBuffer);
            $counts[IndustryData::TABLE_PI_SCHEMATICS] += count($schematicBuffer);
        }

        if ($typeBuffer) {
            DB::table(IndustryData::TABLE_PI_TYPEMAP)->insert($typeBuffer);
            $counts[IndustryData::TABLE_PI_TYPEMAP] += count($typeBuffer);
        }

        fclose($handle);

        return $counts;
    }

    private function truncate(): void
    {
        $tables = array_merge(
            IndustryData::TABLES,
            IndustryData::PI_TABLES,
            IndustryData::EFFECT_TABLES,
            IndustryData::CAPABILITY_TABLES
        );

        foreach ($tables as $table) {
            if (IndustryData::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    /**
     * Assembly lines — what an activity looks like from the structure side: which
     * activity it runs, and which product groups/categories it accepts.
     *
     * The group/category lists are what make "Standup Manufacturing Plant I cannot
     * build a Dreadnought" a fact instead of a rule someone remembered: line 175
     * lists the groups it accepts and its categories exclude ships, while the
     * capital groups only appear on lines 176 and 177.
     */
    private function importAssemblyLines(string $path): array
    {
        $counts = [IndustryData::TABLE_ASSEMBLY_LINES => 0];
        $buffer = [];

        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $line = json_decode($line, true);

            if (! is_array($line)) {
                continue;
            }

            $lineId = (int) ($line['_key'] ?? 0);
            $activityId = (int) ($line['activityID'] ?? 0);

            if (! $lineId || ! $activityId) {
                continue;
            }

            $groups = [];

            foreach (($line['detailsPerGroup'] ?? []) as $detail) {
                $groups[] = (int) ($detail['groupID'] ?? 0);
            }

            $categories = [];

            foreach (($line['detailsPerCategory'] ?? []) as $detail) {
                $categories[] = (int) ($detail['categoryID'] ?? 0);
            }

            $buffer[] = [
                'assemblyLineID' => $lineId,
                'activityID' => $activityId,
                'name' => mb_substr((string) ($line['name'] ?? ''), 0, 255),
                'groupIDs' => json_encode(array_values(array_unique(array_filter($groups)))),
                'categoryIDs' => json_encode(array_values(array_unique(array_filter($categories)))),
                'baseMaterialMultiplier' => $line['baseMaterialMultiplier'] ?? null,
                'baseTimeMultiplier' => $line['baseTimeMultiplier'] ?? null,
                'baseCostMultiplier' => $line['baseCostMultiplier'] ?? null,
            ];

            if (count($buffer) >= 500) {
                DB::table(IndustryData::TABLE_ASSEMBLY_LINES)->insert($buffer);
                $counts[IndustryData::TABLE_ASSEMBLY_LINES] += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            DB::table(IndustryData::TABLE_ASSEMBLY_LINES)->insert($buffer);
            $counts[IndustryData::TABLE_ASSEMBLY_LINES] += count($buffer);
        }

        fclose($handle);

        return $counts;
    }

    /**
     * Service modules -> the assembly lines they provide. Keyed by the module's
     * typeID, which is what SeAT already stores in the `ServiceSlot*` asset rows,
     * so a fitted structure maps straight onto its activities.
     */
    private function importInstallations(string $path): array
    {
        $counts = [IndustryData::TABLE_INSTALLATIONS => 0];
        $buffer = [];

        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $type = json_decode($line, true);

            if (! is_array($type)) {
                continue;
            }

            $typeId = (int) ($type['_key'] ?? 0);

            if (! $typeId) {
                continue;
            }

            $lineIds = [];

            foreach (($type['assemblyLines'] ?? []) as $assemblyLine) {
                $lineIds[] = (int) ($assemblyLine['assemblyLineID'] ?? 0);
            }

            $lineIds = array_values(array_unique(array_filter($lineIds)));

            if (! $lineIds) {
                continue;
            }

            $buffer[] = [
                'typeID' => $typeId,
                'assemblyLineIDs' => json_encode($lineIds),
            ];

            if (count($buffer) >= 500) {
                DB::table(IndustryData::TABLE_INSTALLATIONS)->insert($buffer);
                $counts[IndustryData::TABLE_INSTALLATIONS] += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            DB::table(IndustryData::TABLE_INSTALLATIONS)->insert($buffer);
            $counts[IndustryData::TABLE_INSTALLATIONS] += count($buffer);
        }

        fclose($handle);

        return $counts;
    }

    /**
     * Dogma effects — the definitions SeAT core does not seed. Stored as the
     * plugin's own table so the rig scope can be resolved without a core change.
     *
     * `modifierInfo` is kept as the JSON CCP publishes it; the scope resolver
     * reads `modifiedAttributeID` from it.
     */
    private function importEffects(string $path): array
    {
        $counts = [IndustryData::TABLE_EFFECTS => 0];
        $buffer = [];

        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $effect = json_decode($line, true);

            if (! is_array($effect)) {
                continue;
            }

            $effectId = (int) ($effect['_key'] ?? 0);

            if (! $effectId) {
                continue;
            }

            $buffer[] = [
                'effectID' => $effectId,
                'effectName' => mb_substr((string) ($effect['name'] ?? ''), 0, 400),
                'modifierInfo' => isset($effect['modifierInfo'])
                    ? json_encode($effect['modifierInfo'])
                    : null,
            ];

            if (count($buffer) >= 1000) {
                DB::table(IndustryData::TABLE_EFFECTS)->insert($buffer);
                $counts[IndustryData::TABLE_EFFECTS] += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            DB::table(IndustryData::TABLE_EFFECTS)->insert($buffer);
            $counts[IndustryData::TABLE_EFFECTS] += count($buffer);
        }

        fclose($handle);

        return $counts;
    }
}
