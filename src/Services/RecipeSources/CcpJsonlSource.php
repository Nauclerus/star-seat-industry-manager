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

    private string $work;

    private string $version = 'unknown';

    public function __construct(?string $workDir = null)
    {
        $this->work = $workDir ?? storage_path('sde/industry-manager/');
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
     * Is this source available on the current install?
     *
     * Chosen only when SeAT core actually has the recipe seeders — the day the
     * upstream PR lands, the plugin switches source with no further change.
     */
    public static function available(): bool
    {
        return class_exists(\Seat\Eveapi\Database\Seeders\Sde\Ccp\BlueprintsSeeder::class);
    }

    public function import(): array
    {
        if (! File::exists($this->work)) {
            File::makeDirectory($this->work, 0755, true);
        }

        [$blueprints, $schematics] = $this->locateFiles();

        if (! $blueprints && ! $schematics) {
            throw new \RuntimeException('Could not find or download CCP SDE files (blueprints.jsonl / planetSchematics.jsonl).');
        }

        $rows = [];

        if ($blueprints) {
            $rows = array_merge($rows, $this->importBlueprints($blueprints));
        }

        if ($schematics) {
            $rows = array_merge($rows, $this->importSchematics($schematics));
        }

        return $rows;
    }

    /**
     * @return array{0:?string, 1:?string}
     */
    private function locateFiles(): array
    {
        $blueprints = $this->findUnderStorage('blueprints.jsonl');
        $schematics = $this->findUnderStorage('planetSchematics.jsonl');

        if ($blueprints || $schematics) {
            $this->version = $this->buildFromPath($blueprints ?? $schematics) ?? 'unknown';

            return [$blueprints, $schematics];
        }

        return $this->downloadAndExtract();
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
     * @return array{0:?string, 1:?string}
     */
    private function downloadAndExtract(): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException("The PHP zip extension is required to extract CCP's SDE but is not loaded.");
        }

        $client = new Client(['timeout' => 1200, 'connect_timeout' => 30]);
        $zipPath = $this->work . 'ccp-sde.zip';

        $res = $client->request('GET', self::CCP_LATEST_URL, [
            'sink' => $zipPath,
            'headers' => ['User-Agent' => 'IndustryManager-SeAT-plugin'],
        ]);

        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException('Download failed: HTTP ' . $res->getStatusCode());
        }

        // The redirect target names the build, e.g.
        // .../tranquility/eve-online-static-data-3569502-jsonl.zip
        $effective = (string) ($res->getHeaders()['x-effective-url'][0] ?? '');

        if (preg_match('/static-data-(\d+)-jsonl\.zip/', $effective, $m)) {
            $this->version = $m[1];
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open the downloaded zip.');
        }

        $wanted = ['blueprints.jsonl', 'planetSchematics.jsonl'];
        $found = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i)['name'];

            foreach ($wanted as $w) {
                if (strcasecmp(basename($entry), $w) === 0) {
                    $zip->extractTo($this->work, $entry);
                    $found[$w] = $this->work . $entry;
                }
            }
        }

        $zip->close();

        return [$found['blueprints.jsonl'] ?? null, $found['planetSchematics.jsonl'] ?? null];
    }

    private function importBlueprints(string $path): array
    {
        $this->truncate();

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
        foreach (array_merge(IndustryData::TABLES, IndustryData::PI_TABLES) as $table) {
            if (IndustryData::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
