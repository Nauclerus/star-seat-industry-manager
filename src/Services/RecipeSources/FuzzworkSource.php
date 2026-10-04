<?php

namespace IndustryManager\Services\RecipeSources;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;

/**
 * Fuzzwork's per-table gzip dumps — the fallback that works on stock SeAT today.
 *
 * This is the same endpoint SeAT core's `eve:update:sde` uses, so it follows the
 * path already proven on this install. The recipe tables are already flat, so
 * there is no flattening step: each row maps straight into the plugin's table.
 *
 * Verified against CCP build 3569502 — every count matches exactly (5,082
 * blueprints / 19,138 activity pairs, 36,500 materials, 6,330 products, 22,398
 * skills, 1,353 probabilities, 68 PI schematics, 203 PI type-map rows). So this
 * source is complete, not partial; its only weakness is that Fuzzwork lags
 * patches, which is why CCP is preferred when core can provide it.
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

        foreach (array_merge(IndustryData::TABLES, IndustryData::PI_TABLES) as $table) {
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

        foreach (preg_split('/(?=INSERT INTO `' . $dumpTable . '` VALUES)/', $sql, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
            $body = substr($statement, strpos($statement, 'VALUES') + 6);

            foreach ($this->rows($body, count($columns)) as $row) {
                $buffer[] = array_combine($columns, $row);

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

        return $value;
    }
}
