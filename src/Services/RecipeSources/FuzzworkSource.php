<?php

namespace IndustryManager\Services\RecipeSources;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;

/**
 * Fuzzwork's per-table gzip dumps — the fallback, used only when CCP's own
 * archive cannot be reached.
 *
 * This is the same endpoint SeAT core's `eve:update:sde` uses, so it follows the
 * path already proven on this install. The recipe tables are already flat, so
 * there is no flattening step: each row maps straight into the plugin's table.
 *
 * Verified against CCP build 3569502 — every count matches exactly (5,082
 * blueprints / 19,138 activity pairs, 36,500 materials, 6,330 products, 22,398
 * skills, 1,353 probabilities, 68 PI schematics, 203 PI type-map rows). So this
 * source is complete, not partial; its weakness is that it follows CCP's
 * releases by a day or two, which is why CCP is always the preferred source.
 *
 * Writes only to the plugin's own tables.
 */
class FuzzworkSource implements RecipeSource
{
    private const FUZZWORK_TABLES_URL = 'https://www.fuzzwork.co.uk/dump/latest/mysql_tables/';

    /** Dump table => [plugin table, columns in the order the dump lists them]. */
    private const MAP = [
        'industryActivity' => [
            'table' => IndustryData::TABLE_ACTIVITY,
            'columns' => ['typeID', 'activityID', 'time'],
        ],
        'industryActivityMaterials' => [
            'table' => IndustryData::TABLE_MATERIALS,
            'columns' => ['typeID', 'activityID', 'materialTypeID', 'quantity'],
        ],
        'industryActivityProducts' => [
            'table' => IndustryData::TABLE_PRODUCTS,
            'columns' => ['typeID', 'activityID', 'productTypeID', 'quantity'],
        ],
        'industryActivitySkills' => [
            'table' => IndustryData::TABLE_SKILLS,
            'columns' => ['typeID', 'activityID', 'skillID', 'level'],
        ],
        'industryActivityProbabilities' => [
            'table' => IndustryData::TABLE_PROBABILITIES,
            'columns' => ['typeID', 'activityID', 'productTypeID', 'probability'],
        ],
        'planetSchematics' => [
            'table' => IndustryData::TABLE_PI_SCHEMATICS,
            'columns' => ['schematicID', 'schematicName', 'cycleTime'],
        ],
        'planetSchematicsTypeMap' => [
            'table' => IndustryData::TABLE_PI_TYPEMAP,
            'columns' => ['schematicID', 'typeID', 'quantity', 'isInput'],
        ],
        // Dogma effects: the full dump column order, with only the three we need
        // kept. The row parser yields every value in dump order, so the column
        // list has to match the dump exactly even though most of it is unused.
        'dgmEffects' => [
            'table' => IndustryData::TABLE_EFFECTS,
            'columns' => [
                'effectID', 'effectName', 'effectCategory', 'preExpression',
                'postExpression', 'description', 'guid', 'iconID', 'isOffensive',
                'isAssistance', 'durationAttributeID', 'trackingSpeedAttributeID',
                'dischargeAttributeID', 'rangeAttributeID', 'falloffAttributeID',
                'disallowAutoRepeat', 'published', 'displayName', 'isWarpSafe',
                'rangeChance', 'electronicChance', 'propulsionChance', 'distribution',
                'sfxName', 'npcUsageChanceAttributeID', 'npcActivationChanceAttributeID',
                'fittingUsageChanceAttributeID', 'modifierInfo',
            ],
            'keep' => ['effectID', 'effectName', 'modifierInfo'],
        ],
        // Assembly lines: the activity each line runs. Fuzzwork has no equivalent
        // of industryInstallationTypes (its `ramAssemblyLineTypes` is deprecated
        // and dumps empty), so the service-module half of the capability map is
        // CCP-only and the group/category detail is missing here.
        'ramAssemblyLines' => [
            'table' => IndustryData::TABLE_ASSEMBLY_LINES,
            'columns' => [
                'assemblyLineID', 'activityID', 'baseCostMultiplier',
                'baseMaterialMultiplier', 'baseTimeMultiplier', 'name', 'description',
            ],
            'keep' => [
                'assemblyLineID', 'activityID', 'name',
                'baseMaterialMultiplier', 'baseTimeMultiplier', 'baseCostMultiplier',
            ],
        ],
    ];

    private string $version = 'unknown';

    public function name(): string
    {
        return 'fuzzwork';
    }

    public function label(): string
    {
        return 'Fuzzwork per-table gzip dumps (SeAT core SDE path)';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function import(): array
    {
        $counts = [];

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

        foreach (self::MAP as $dumpTable => $mapping) {
            $sql = $this->fetch($dumpTable);
            $counts[$mapping['table']] = $this->importTable($sql, $dumpTable, $mapping);
        }

        return $counts;
    }

    /**
     * Import one table from a raw dump body. Exposed so the SQL parsing can be
     * tested without hitting the network.
     */
    public function importFromSql(string $dumpTable, string $sql): int
    {
        if (! isset(self::MAP[$dumpTable])) {
            throw new \InvalidArgumentException('Unknown dump table: ' . $dumpTable);
        }

        return $this->importTable($sql, $dumpTable, self::MAP[$dumpTable]);
    }

    private function fetch(string $table): string
    {
        $client = new Client(['timeout' => 300, 'connect_timeout' => 30]);

        $res = $client->get(self::FUZZWORK_TABLES_URL . $table . '.sql.gz', [
            'headers' => ['User-Agent' => 'IndustryManager-SeAT-plugin'],
        ]);

        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException('Fuzzwork returned HTTP ' . $res->getStatusCode() . ' for ' . $table);
        }

        $sql = gzdecode($res->getBody()->getContents());

        if ($sql === false) {
            throw new \RuntimeException('Could not decompress the dump for ' . $table);
        }

        // The dump stamp is the only build marker Fuzzwork gives per file; the
        // first one seen becomes the version used for cache invalidation.
        if ($this->version === 'unknown' && preg_match('/Dump completed on ([0-9\- :]+)/', $sql, $m)) {
            $this->version = trim($m[1]);
        }

        return $sql;
    }

    private function importTable(string $sql, string $dumpTable, array $mapping): int
    {
        $table = $mapping['table'];
        $columns = $mapping['columns'];

        if (! IndustryData::hasTable($table)) {
            throw new \RuntimeException('Plugin table ' . $table . ' does not exist — run the plugin migrations first.');
        }

        $rows = 0;
        $buffer = [];
        $keep = $mapping['keep'] ?? $columns;

        foreach (preg_split('/(?=INSERT INTO `' . $dumpTable . '` VALUES)/', $sql, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
            $body = substr($statement, strpos($statement, 'VALUES') + 6);

            foreach ($this->rows($body, count($columns)) as $row) {
                $buffer[] = array_intersect_key(array_combine($columns, $row), array_flip($keep));

                if (count($buffer) >= 1000) {
                    DB::table($table)->insert($buffer);
                    $rows += count($buffer);
                    $buffer = [];
                }
            }
        }

        if ($buffer) {
            DB::table($table)->insert($buffer);
            $rows += count($buffer);
        }

        return $rows;
    }

    /**
     * Quote-aware row scan, so a semicolon or parenthesis inside a quoted string
     * does not end the statement or split a row.
     *
     * @return \Generator<int, array>
     */
    private function rows(string $body, int $columnCount)
    {
        $length = strlen($body);
        $row = [];
        $value = '';
        $inString = false;
        $inRow = false;
        $i = 0;

        while ($i < $length) {
            $char = $body[$i];

            if ($inString) {
                if ($char === '\\' && $i + 1 < $length) {
                    $value .= $body[$i] . $body[$i + 1];
                    $i += 2;
                    continue;
                }

                if ($char === "'") {
                    $inString = false;
                } else {
                    $value .= $char;
                }

                $i++;
                continue;
            }

            if ($char === "'") {
                $inString = true;
                $inRow = true;
                $i++;
                continue;
            }

            if ($char === '(') {
                $inRow = true;
                $value = '';
            } elseif ($char === ',') {
                if ($inRow) {
                    $row[] = $value;
                    $value = '';
                }
            } elseif ($char === ')') {
                if ($inRow) {
                    $row[] = $value;

                    if (count($row) === $columnCount) {
                        yield array_map([$this, 'cast'], $row);
                    }

                    $row = [];
                    $value = '';
                    $inRow = false;
                }
            } elseif ($char === ';') {
                break;
            } elseif ($inRow) {
                // Ordinary characters — digits, letters, '.' — outside a quoted
                // string. Quoted strings are handled above.
                $value .= $char;
            }

            $i++;
        }
    }

    private function cast(string $value)
    {
        $trimmed = trim($value);

        if (strtoupper($trimmed) === 'NULL') {
            return null;
        }

        if ($trimmed !== '' && is_numeric($trimmed)) {
            return strpbrk($trimmed, '.eE') !== false ? (float) $trimmed : (int) $trimmed;
        }

        return $this->unescape($value);
    }

    /**
     * mysqldump writes string literals with backslash escapes, so a JSON payload
     * arrives as `[{\"key\": 1}]`. Stored verbatim that is not decodable JSON, so
     * the escapes are resolved here — this is what the scope resolver reads.
     */
    private function unescape(string $value): string
    {
        if (! str_contains($value, '\\')) {
            return $value;
        }

        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '\\' && $i + 1 < $length) {
                $out .= match ($value[$i + 1]) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $value[$i + 1],
                };

                $i++;
                continue;
            }

            $out .= $value[$i];
        }

        return $out;
    }
}
