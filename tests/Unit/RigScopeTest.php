<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\RigScope;
use PHPUnit\Framework\TestCase;

/**
 * The scope layer. The rig side is data-driven (effect name -> multiplier
 * attribute); the product side is the curated part, so these tests pin the
 * curated map against the values confirmed from the live SDE on 2026-10-04.
 */
class RigScopeTest extends TestCase
{
    public function test_effect_names_resolve_to_scope_tokens(): void
    {
        $cases = [
            'rigSmallshipManufactureMaterialBonus' => RigScope::BAS_SMALL_SHIP,
            'rigMediumshipManufactureMaterialBonus' => RigScope::BAS_MEDIUM_SHIP,
            'rigMediumshipsManufactureTimeBonus' => RigScope::BAS_MEDIUM_SHIP,
            'rigLargeshipManufactureMaterialBonus' => RigScope::BAS_LARGE_SHIP,
            'rigAdvSmshipManufactureMaterialBonus' => RigScope::ADV_SMALL_SHIP,
            'rigAdvMedShipManufactureMaterialBonus' => RigScope::ADV_MEDIUM_SHIP,
            'rigAdvLarShipManufactureMaterialBonus' => RigScope::ADV_LARGE_SHIP,
            'rigCapShipManufactureMaterialBonus' => RigScope::CAP_SHIP,
            'rigAllShipManufactureTimeBonus' => RigScope::ALL_SHIP,
            'rigAdvComponentManufactureMaterialBonus' => RigScope::ADV_COMPONENT,
            'rigBasCapCompManufactureMaterialBonus' => RigScope::BAS_CAP_COMP,
            'rigAdvCapComponentManufactureMaterialBonus' => RigScope::ADV_CAP_COMP,
            'rigStructureManufactureMaterialBonus' => RigScope::STRUCTURE,
            'rigAmmoManufactureMaterialBonus' => RigScope::AMMO,
            'rigDroneManufactureMaterialBonus' => RigScope::DRONE,
            'rigEquipmentManufactureMaterialBonus' => RigScope::EQUIPMENT,
        ];

        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, RigScope::tokenFromEffectName($name), $name);
        }
    }

    public function test_thukker_effect_names_share_the_base_scope_tokens(): void
    {
        $this->assertSame(RigScope::BAS_CAP_COMP, RigScope::tokenFromEffectName('rigThukkerBasCapCompManufactureMaterialBonus'));
        $this->assertSame(RigScope::ADV_CAP_COMP, RigScope::tokenFromEffectName('rigThukkerAdvCapComponentManufactureMaterialBonus'));
    }

    public function test_reaction_effect_names_map_to_reaction_families(): void
    {
        $this->assertSame(RigScope::REACTION_BIO, RigScope::tokenFromEffectName('rigReactionBioMatBonus'));
        $this->assertSame(RigScope::REACTION_BIO, RigScope::tokenFromEffectName('rigReactionBioTimeBonus'));
        $this->assertSame(RigScope::REACTION_CHEMICAL, RigScope::tokenFromEffectName('rigReactionCompMatBonus'));
        $this->assertSame(RigScope::REACTION_HYBRID, RigScope::tokenFromEffectName('rigReactionHybTimeBonus'));
    }

    public function test_multiplier_attributes_match_the_live_sde(): void
    {
        // Confirmed from dogmaEffects.jsonl: 43867 writes 2557, 43870 writes 2559,
        // 43875 writes 2561, and the advanced capital component effect writes 2658.
        $this->assertSame([2557, 2558], RigScope::multiplierAttributes(IndustryActivity::MANUFACTURING, RigScope::ADV_COMPONENT));
        $this->assertSame([2559, 2560], RigScope::multiplierAttributes(IndustryActivity::MANUFACTURING, RigScope::BAS_CAP_COMP));
        $this->assertSame([2658, 2659], RigScope::multiplierAttributes(IndustryActivity::MANUFACTURING, RigScope::ADV_CAP_COMP));
        $this->assertSame([2561, 2562], RigScope::multiplierAttributes(IndustryActivity::MANUFACTURING, RigScope::STRUCTURE));
    }

    public function test_reaction_jobs_use_their_own_family_attributes(): void
    {
        // Confirmed from dogmaEffects.jsonl: rigReactionCompMatBonus writes 2718,
        // rigReactionCompTimeBonus 2717, bio 2720/2719, hybrid 2716/2715.
        $this->assertSame([2718, 2717], RigScope::multiplierAttributes(IndustryActivity::REACTIONS, RigScope::REACTION_CHEMICAL));
        $this->assertSame([2720, 2719], RigScope::multiplierAttributes(IndustryActivity::REACTIONS, RigScope::REACTION_BIO));
        $this->assertSame([2716, 2715], RigScope::multiplierAttributes(IndustryActivity::REACTIONS, RigScope::REACTION_HYBRID));

        // Scope-based means the product decides: a product with no scope has no
        // attribute, and the caller falls back rather than guessing a family.
        $this->assertNull(RigScope::multiplierAttributes(IndustryActivity::REACTIONS, RigScope::NONE));
    }

    public function test_activities_without_scope_use_fixed_attributes(): void
    {
        $this->assertSame([2563, 2564], RigScope::multiplierAttributes(IndustryActivity::INVENTION, 'anything'));
        $this->assertSame([2565, 2566], RigScope::multiplierAttributes(IndustryActivity::RESEARCH_ME, 'anything'));
        $this->assertSame([2567, 2568], RigScope::multiplierAttributes(IndustryActivity::RESEARCH_TE, 'anything'));
        $this->assertSame([2569, 2570], RigScope::multiplierAttributes(IndustryActivity::COPYING, 'anything'));
    }

    public function test_component_groups_map_to_the_component_scopes(): void
    {
        $this->assertSame(RigScope::ADV_COMPONENT, RigScope::tokenForProduct(334, 'Construction Components', 'Commodity', 1));
        $this->assertSame(RigScope::ADV_COMPONENT, RigScope::tokenForProduct(332, 'Tool', 'Commodity', 1));
        $this->assertSame(RigScope::BAS_CAP_COMP, RigScope::tokenForProduct(873, 'Capital Construction Components', 'Commodity', 1));
        $this->assertSame(RigScope::ADV_CAP_COMP, RigScope::tokenForProduct(913, 'Advanced Capital Construction Components', 'Commodity', 2));
    }

    public function test_reaction_groups_are_data_driven(): void
    {
        $this->assertSame(RigScope::REACTION_CHEMICAL, RigScope::tokenForProduct(436, 'Simple Reaction', 'Reaction', 1));
        $this->assertSame(RigScope::REACTION_BIO, RigScope::tokenForProduct(661, 'Simple Biochemical Reactions', 'Reaction', 1));
        $this->assertSame(RigScope::REACTION_HYBRID, RigScope::tokenForProduct(977, 'Hybrid Reactions', 'Reaction', 1));
    }

    public function test_ship_scopes_split_by_size_and_tech_level(): void
    {
        $this->assertSame(RigScope::BAS_SMALL_SHIP, RigScope::tokenForProduct(25, 'Frigate', 'Ship', 1));
        $this->assertSame(RigScope::ADV_SMALL_SHIP, RigScope::tokenForProduct(324, 'Assault Frigate', 'Ship', 2));
        $this->assertSame(RigScope::BAS_MEDIUM_SHIP, RigScope::tokenForProduct(26, 'Cruiser', 'Ship', 1));
        $this->assertSame(RigScope::ADV_MEDIUM_SHIP, RigScope::tokenForProduct(358, 'Heavy Assault Cruiser', 'Ship', 2));
        $this->assertSame(RigScope::BAS_LARGE_SHIP, RigScope::tokenForProduct(27, 'Battleship', 'Ship', 1));
        $this->assertSame(RigScope::ADV_LARGE_SHIP, RigScope::tokenForProduct(900, 'Marauder', 'Ship', 2));
        $this->assertSame(RigScope::CAP_SHIP, RigScope::tokenForProduct(547, 'Carrier', 'Ship', 1));
    }

    public function test_other_categories_map_straight_to_a_scope(): void
    {
        $this->assertSame(RigScope::DRONE, RigScope::tokenForProduct(100, 'Combat Drone', 'Drone', 1));
        $this->assertSame(RigScope::AMMO, RigScope::tokenForProduct(83, 'Projectile Ammo', 'Charge', 1));
        $this->assertSame(RigScope::EQUIPMENT, RigScope::tokenForProduct(40, 'Shield Booster', 'Module', 1));
        $this->assertSame(RigScope::STRUCTURE, RigScope::tokenForProduct(1404, 'Engineering Complex', 'Structure', 1));
        $this->assertSame(RigScope::NONE, RigScope::tokenForProduct(18, 'Mineral', 'Material', 1));
        $this->assertSame(RigScope::NONE, RigScope::tokenForProduct(null, null, null, null));
    }

    public function test_all_ship_rig_covers_every_ship_scope_only(): void
    {
        $this->assertTrue(RigScope::applies(RigScope::ALL_SHIP, RigScope::ADV_SMALL_SHIP));
        $this->assertTrue(RigScope::applies(RigScope::ALL_SHIP, RigScope::CAP_SHIP));
        $this->assertFalse(RigScope::applies(RigScope::ALL_SHIP, RigScope::ADV_COMPONENT));

        $this->assertTrue(RigScope::applies(RigScope::ADV_COMPONENT, RigScope::ADV_COMPONENT));
        $this->assertFalse(RigScope::applies(RigScope::ADV_COMPONENT, RigScope::BAS_CAP_COMP));
        $this->assertFalse(RigScope::applies(RigScope::BAS_SMALL_SHIP, RigScope::NONE));
        $this->assertFalse(RigScope::applies(null, RigScope::ADV_COMPONENT));
    }

    public function test_attribute_ids_round_trip_back_to_a_token(): void
    {
        $this->assertSame(RigScope::ADV_COMPONENT, RigScope::tokenForAttribute(2557));
        $this->assertSame(RigScope::ADV_COMPONENT, RigScope::tokenForAttribute(2558));
        $this->assertSame(RigScope::BAS_CAP_COMP, RigScope::tokenForAttribute(2559));
        $this->assertNull(RigScope::tokenForAttribute(2594)); // the rig's own bonus, not a scope
    }
}
