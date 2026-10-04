<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\StructureServices;
use IndustryManager\Tests\TestCase;

/**
 * Whether SeAT has actually read the inside of a structure.
 *
 * The corporation asset route is not all-or-nothing: a character without reach into
 * a citadel still reports the office folder and the corporate deliveries hanging off
 * it, and nothing else. Read naively that looks like a structure whose contents are
 * known and empty, which would hide a structure that may well have a manufacturing
 * plant fitted in it.
 */
class StructureServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        IndustryData::flush();
    }

    public function test_office_rows_alone_do_not_count_as_having_read_the_inside(): void
    {
        $this->asset(1001, 'OfficeFolder');
        $this->asset(1001, 'CorpDeliveries');

        $this->assertSame([], $this->known([1001]));
    }

    public function test_the_core_and_fuel_array_every_upwell_structure_has_do_count(): void
    {
        $this->asset(1002, 'QuantumCoreRoom');
        $this->asset(1003, 'StructureFuel');

        $this->assertEqualsCanonicalizing([1002, 1003], $this->known([1002, 1003]));
    }

    public function test_a_module_in_a_slot_counts_even_without_the_rest(): void
    {
        $this->asset(1004, 'ServiceSlot0');
        $this->asset(1005, 'RigSlot1');

        $this->assertEqualsCanonicalizing([1004, 1005], $this->known([1004, 1005]));
    }

    public function test_a_structure_with_no_asset_rows_at_all_is_not_known(): void
    {
        $this->asset(1006, 'QuantumCoreRoom');

        $this->assertSame([1006], $this->known([1006, 1007]));
    }

    private function known(array $structureIds): array
    {
        return (new StructureServices())->structuresWithKnownContents($structureIds);
    }

    private function asset(int $structureId, string $flag): void
    {
        DB::table('corporation_assets')->insert([
            'type_id' => 35878,
            'corporation_id' => 1,
            'location_id' => $structureId,
            'location_flag' => $flag,
            'quantity' => 1,
        ]);
    }
}
