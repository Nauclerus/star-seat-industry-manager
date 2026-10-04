<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\RecipeSources\FuzzworkSource;
use IndustryManager\Services\StructureIndustryRigs;
use IndustryManager\Tests\TestCase;

/**
 * The Fuzzwork SQL parser. Its job is to read mysqldump INSERT statements, and
 * the failure mode to guard against is a semicolon or parenthesis inside a
 * quoted string ending the statement early.
 */
class FuzzworkSourceTest extends TestCase
{
    public function test_parses_rows_across_multiple_insert_statements(): void
    {
        $sql = "LOCK TABLES `planetSchematics` WRITE;\n"
            . "INSERT INTO `planetSchematics` VALUES (65,'Superconductors',3600),(66,'Coolant',3600);\n"
            . "INSERT INTO `planetSchematics` VALUES (67,'Rocket Fuel',1800);\n"
            . "UNLOCK TABLES;";

        $rows = (new FuzzworkSource())->importFromSql('planetSchematics', $sql);

        $this->assertSame(3, $rows);
        $this->assertSame(3, DB::table(IndustryData::TABLE_PI_SCHEMATICS)->count());
    }

    public function test_quoted_semicolon_does_not_end_the_statement(): void
    {
        $sql = "INSERT INTO `planetSchematics` VALUES (67,'Semi;colon (x)',3600);";

        (new FuzzworkSource())->importFromSql('planetSchematics', $sql);

        $this->assertSame(
            'Semi;colon (x)',
            DB::table(IndustryData::TABLE_PI_SCHEMATICS)->where('schematicID', 67)->value('schematicName')
        );
    }

    public function test_null_and_integer_values_are_cast(): void
    {
        $sql = "INSERT INTO `industryActivityProbabilities` VALUES (685,8,165,0.34),(686,8,166,NULL);";

        (new FuzzworkSource())->importFromSql('industryActivityProbabilities', $sql);

        $this->assertSame(0.34, (float) DB::table(IndustryData::TABLE_PROBABILITIES)->where('typeID', 685)->value('probability'));
        $this->assertNull(DB::table(IndustryData::TABLE_PROBABILITIES)->where('typeID', 686)->value('probability'));
    }

    public function test_parses_a_real_fuzzwork_dump(): void
    {
        $gz = file_get_contents(__DIR__ . '/../fixtures/planetSchematics.real.sql.gz');
        $sql = gzdecode($gz);

        $this->assertNotFalse($sql);

        $rows = (new FuzzworkSource())->importFromSql('planetSchematics', $sql);

        // Fuzzwork's latest dump carries 68 schematics.
        $this->assertSame(68, $rows);
        $this->assertSame(68, DB::table(IndustryData::TABLE_PI_SCHEMATICS)->count());
        $this->assertSame(
            'Superconductors',
            DB::table(IndustryData::TABLE_PI_SCHEMATICS)->where('schematicID', 65)->value('schematicName')
        );
    }

    public function test_targeted_parse_does_not_clear_existing_rows(): void
    {
        DB::table(IndustryData::TABLE_PI_SCHEMATICS)->insert([
            'schematicID' => 1, 'schematicName' => 'Stale', 'cycleTime' => 1,
        ]);

        // A single-table import does not clear by itself; the full import() does.
        // This asserts the row survives a targeted parse, which is what the
        // seam promises, so tests do not accidentally depend on clearing.
        (new FuzzworkSource())->importFromSql('planetSchematics', "INSERT INTO `planetSchematics` VALUES (2,'Fresh',60);");

        $this->assertSame(2, DB::table(IndustryData::TABLE_PI_SCHEMATICS)->count());
    }

    /**
     * dgmEffects has 28 dump columns but the plugin only keeps three. The row
     * parser has to count all 28 to know where a row ends, so this guards the
     * keep-subset as well as the parse.
     */
    public function test_dgm_effects_keeps_only_the_columns_the_scope_needs(): void
    {
        $columns = str_repeat('NULL,', 17);

        $sql = "INSERT INTO `dgmEffects` VALUES (6824,'rigAdvComponentManufactureMaterialBonus',0,NULL,NULL,'desc','',NULL,0,0,"
            . $columns
            . "'[{\\\"domain\\\": \\\"structureID\\\", \\\"func\\\": \\\"ItemModifier\\\", \\\"modifiedAttributeID\\\": 2557}]');";

        $rows = (new FuzzworkSource())->importFromSql('dgmEffects', $sql);

        $this->assertSame(1, $rows);

        $row = DB::table(IndustryData::TABLE_EFFECTS)->where('effectID', 6824)->first();

        $this->assertSame('rigAdvComponentManufactureMaterialBonus', $row->effectName);
        $this->assertSame([2557], StructureIndustryRigs::writtenAttributes($row->modifierInfo));
    }
}
