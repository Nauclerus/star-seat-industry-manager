<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\RigScope;
use IndustryManager\Services\StructureIndustryRigs;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of the rig resolver. The Eloquent half needs SeAT's
 * CorporationStructure wiring, so these tests drive bonusesFor() with a fit built
 * by hand — which is exactly the shape resolve() produces.
 *
 * The point being guarded: a fit with two ME rigs of different scopes must apply
 * only the rig that covers the job, not the best of the two.
 */
class StructureIndustryRigsTest extends TestCase
{
    public function test_modifier_info_keeps_only_structure_item_modifiers(): void
    {
        $json = '[{"domain":"structureID","func":"ItemModifier","modifiedAttributeID":2557,"modifyingAttributeID":2594,"operation":6},'
            . '{"domain":"shipID","func":"ItemModifier","modifiedAttributeID":564,"modifyingAttributeID":2435,"operation":6}]';

        $this->assertSame([2557], StructureIndustryRigs::writtenAttributes($json));
        $this->assertSame([], StructureIndustryRigs::writtenAttributes(null));
        $this->assertSame([], StructureIndustryRigs::writtenAttributes('not json'));
    }

    public function test_modifier_info_from_a_stored_fuzzwork_row_decodes(): void
    {
        // The dump writes this as `[{\"domain\": ...}]`; the parser unescapes it on
        // the way into the table, so what the resolver reads is plain JSON.
        $json = '[{"domain": "structureID", "func": "ItemModifier", "modifiedAttributeID": 2557, "modifyingAttributeID": 2594, "operation": 6}]';

        $this->assertSame([2557], StructureIndustryRigs::writtenAttributes($json));
    }

    public function test_only_the_rig_that_covers_the_job_applies(): void
    {
        $fit = $this->fit([
            $this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [2550, 2594]),
            $this->rig(43867, 'Advanced Component ME', ['me' => -3.0], [2557, 2658, 2594]),
        ]);

        // An advanced component job must take the component rig (6.3%), not the
        // small-ship rig (4.2%), even though the small-ship rig is fitted.
        $component = StructureIndustryRigs::bonusesFor($fit, IndustryActivity::MANUFACTURING, RigScope::ADV_COMPONENT);

        $this->assertSame(6.3, $component['me']);
        $this->assertSame(0.0, $component['te']);
        $this->assertSame(43867, $component['rig']['type_id']);
        $this->assertSame('scope', $component['source']);

        $ship = StructureIndustryRigs::bonusesFor($fit, IndustryActivity::MANUFACTURING, RigScope::ADV_SMALL_SHIP);

        $this->assertSame(4.2, $ship['me']);
        $this->assertSame(43855, $ship['rig']['type_id']);
    }

    public function test_a_job_outside_every_fitted_scope_gets_no_rig_bonus(): void
    {
        $fit = $this->fit([
            $this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [2550, 2594]),
        ]);

        $structure = StructureIndustryRigs::bonusesFor($fit, IndustryActivity::MANUFACTURING, RigScope::STRUCTURE);

        $this->assertSame(0.0, $structure['me']);
        $this->assertSame('uncovered', $structure['source']);
        $this->assertNull($structure['rig']);
    }

    public function test_time_and_material_rigs_are_matched_per_attribute(): void
    {
        $fit = $this->fit([
            $this->rig(43856, 'Advanced Small Ship TE', ['te' => -20.0], [2551, 2593]),
            $this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [2550, 2594]),
        ]);

        $job = StructureIndustryRigs::bonusesFor($fit, IndustryActivity::MANUFACTURING, RigScope::ADV_SMALL_SHIP);

        $this->assertSame(42.0, $job['te']);
        $this->assertSame(4.2, $job['me']);
    }

    public function test_a_combined_xl_rig_counts_for_both_of_its_own_attributes(): void
    {
        // A combined rig has one effect per bonus, so it writes both the material
        // and the time multiplier for the scopes it covers.
        $fit = $this->fit([
            $this->rig(45548, 'Thukker XL Structure and Component', ['te' => -20.0, 'me' => -3.7], [2561, 2562, 2593, 2653]),
        ]);

        $job = StructureIndustryRigs::bonusesFor($fit, IndustryActivity::MANUFACTURING, RigScope::STRUCTURE);

        // Nullsec: TE 20 x 2.1 = 42, Thukker ME 3.7 x 2.1 = 7.77.
        $this->assertSame(42.0, $job['te']);
        $this->assertSame(7.77, $job['me']);
    }

    public function test_thukker_me_is_scored_at_the_band_multiplier_not_the_default(): void
    {
        // A Thukker rig in null-sec is 10% effective; in low-sec it is 1.9x.
        $nullsec = $this->fit([
            $this->rig(45544, 'Thukker BasCapComp ME', ['me' => -3.7], [2559, 2653], 0.1),
        ]);

        $job = StructureIndustryRigs::bonusesFor($nullsec, IndustryActivity::MANUFACTURING, RigScope::BAS_CAP_COMP);

        $this->assertSame(0.37, $job['me']);

        $lowsec = $this->fit([
            $this->rig(45544, 'Thukker BasCapComp ME', ['me' => -3.7], [2559, 2653], 1.9),
        ]);

        $this->assertSame(7.03, StructureIndustryRigs::bonusesFor($lowsec, IndustryActivity::MANUFACTURING, RigScope::BAS_CAP_COMP)['me']);
    }

    public function test_falls_back_to_the_fit_wide_value_when_effects_are_absent(): void
    {
        $fit = $this->fit([$this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [])]);

        $job = StructureIndustryRigs::bonusesFor($fit, IndustryActivity::MANUFACTURING, RigScope::ADV_COMPONENT);

        // No effect definitions means no scope match, so the fit-wide best is used
        // and the source says so rather than pretending the scope matched.
        $this->assertSame(4.2, $job['me']);
        $this->assertSame('fitted', $job['source']);
    }

    public function test_modifiers_accept_a_scope_and_default_to_the_fit_wide_value(): void
    {
        $fit = $this->fit([
            $this->rig(43855, 'Advanced Small Ship ME', ['me' => -2.0], [2550, 2594]),
        ]);

        $this->assertSame(0.958, round(StructureIndustryRigs::materialModifier($fit, IndustryActivity::MANUFACTURING, RigScope::ADV_SMALL_SHIP), 3));
        $this->assertSame(0.958, round(StructureIndustryRigs::materialModifier($fit), 3));
        $this->assertSame(1.0, round(StructureIndustryRigs::materialModifier($fit, IndustryActivity::MANUFACTURING, RigScope::AMMO), 3));
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * @param array<int, array> $rigs
     */
    private function fit(array $rigs): array
    {
        $best = ['me' => 0.0, 'te' => 0.0, 'cost' => 0.0];

        foreach ($rigs as $rig) {
            foreach ($rig['effective_by_label'] as $label => $effective) {
                $best[$label] = max($best[$label], $effective);
            }
        }

        return [
            'band' => 'nullsec',
            'multiplier' => 2.1,
            'rigs' => $rigs,
            'me_bonus' => $best['me'],
            'te_bonus' => $best['te'],
            'cost_bonus' => $best['cost'],
            'source' => $rigs ? 'fitted' : 'base',
        ];
    }

    /**
     * @param array<string,float> $bonuses  label => raw bonus
     * @param array<int,int>      $attributes multiplier attributes the rig writes
     */
    private function rig(int $typeId, string $name, array $bonuses, array $attributes, float $multiplier = 2.1): array
    {
        $effectiveByLabel = [];

        foreach ($bonuses as $label => $raw) {
            $effectiveByLabel[$label] = round(abs($raw) * $multiplier, 2);
        }

        $primary = array_key_first($bonuses);

        return [
            'type_id' => $typeId,
            'name' => $name,
            'bonus' => $primary,
            'raw' => round($bonuses[$primary], 2),
            'multiplier' => round($multiplier, 2),
            'effective' => $effectiveByLabel[$primary],
            'bonuses' => $bonuses,
            'effective_by_label' => $effectiveByLabel,
            'attributes' => $attributes,
            'scopes' => [],
        ];
    }
}
