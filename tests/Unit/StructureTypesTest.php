<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\StructureTypes;
use PHPUnit\Framework\TestCase;

/**
 * The curated gate that stands in when the capability tables are empty. It follows
 * the canFitShipGroup and canFitShipType attributes CCP publishes on the service
 * modules, so the shape of what fits where is the shape CCP publishes.
 */
class StructureTypesTest extends TestCase
{
    public function test_reactions_need_a_refinery(): void
    {
        foreach (StructureTypes::REFINERIES as $typeId) {
            $this->assertTrue(
                StructureTypes::fallbackAllows($typeId, IndustryActivity::REACTIONS),
                'refinery ' . $typeId
            );
        }

        foreach (array_merge(StructureTypes::ENGINEERING_COMPLEXES, StructureTypes::CITADELS) as $typeId) {
            $this->assertFalse(
                StructureTypes::fallbackAllows($typeId, IndustryActivity::REACTIONS),
                'not a refinery: ' . $typeId
            );
        }
    }

    public function test_the_service_modules_of_the_ordinary_activities_fit_all_three_families(): void
    {
        $activities = [
            IndustryActivity::MANUFACTURING,
            IndustryActivity::COPYING,
            IndustryActivity::INVENTION,
            IndustryActivity::RESEARCH_TE,
            IndustryActivity::RESEARCH_ME,
        ];

        foreach ($activities as $activityId) {
            foreach (StructureTypes::SERVICE_HOSTS as $typeId) {
                $this->assertTrue(
                    StructureTypes::fallbackAllows($typeId, $activityId, 334),
                    'activity ' . $activityId . ' type ' . $typeId
                );
            }
        }
    }

    public function test_capital_ships_need_the_structures_the_capital_shipyard_fits_in(): void
    {
        foreach (StructureTypes::CAPITAL_SHIPYARD_HOSTS as $typeId) {
            $this->assertTrue(
                StructureTypes::fallbackAllows($typeId, IndustryActivity::MANUFACTURING, 485),
                'host ' . $typeId
            );
        }

        // A Raitaru, an Astrahus and a refinery cannot host the capital shipyard.
        foreach ([StructureTypes::RAITARU, StructureTypes::ASTRAHUS, StructureTypes::ATHANOR] as $typeId) {
            $this->assertFalse(
                StructureTypes::fallbackAllows($typeId, IndustryActivity::MANUFACTURING, 485),
                'not a host: ' . $typeId
            );
        }
    }

    public function test_supercapital_ships_need_a_sotiyo(): void
    {
        $this->assertTrue(
            StructureTypes::fallbackAllows(StructureTypes::SOTIYO, IndustryActivity::MANUFACTURING, 30)
        );

        foreach (StructureTypes::SERVICE_HOSTS as $typeId) {
            if ($typeId === StructureTypes::SOTIYO) {
                continue;
            }

            $this->assertFalse(
                StructureTypes::fallbackAllows($typeId, IndustryActivity::MANUFACTURING, 659),
                'not a Sotiyo: ' . $typeId
            );
        }
    }

    public function test_only_a_structure_with_service_slots_is_an_industry_structure(): void
    {
        // The question the structures list asks: can a service module go in this
        // structure at all? Everything on the list can, and a structure that cannot
        // hold one is not an industry structure however industry-adjacent it looks.
        foreach (StructureTypes::SERVICE_HOSTS as $typeId) {
            $this->assertTrue(StructureTypes::isServiceHost($typeId), 'host ' . $typeId);
        }

        // A moon drill has modules fitted *in* it and a service module is fitted in
        // a service host *using* it: neither is a place an activity happens.
        foreach ([45009, 82941, 81826, 35899, 35878] as $typeId) {
            $this->assertFalse(StructureTypes::isServiceHost($typeId), 'not a host: ' . $typeId);
        }
    }

    public function test_the_security_limits(): void
    {
        // Reactions: 0.4 is the top, 0.5 is highsec and does not exist.
        $this->assertTrue(StructureTypes::securityAllows(0.4, IndustryActivity::REACTIONS));
        $this->assertTrue(StructureTypes::securityAllows(-0.7, IndustryActivity::REACTIONS));
        $this->assertFalse(StructureTypes::securityAllows(0.5, IndustryActivity::REACTIONS));

        // An unknown security is not a permission.
        $this->assertFalse(StructureTypes::securityAllows(null, IndustryActivity::REACTIONS));

        // Capital and supercapital builds: the shipyard cannot be onlined in highsec.
        $this->assertTrue(StructureTypes::securityAllows(0.4, IndustryActivity::MANUFACTURING, 485));
        $this->assertFalse(StructureTypes::securityAllows(0.5, IndustryActivity::MANUFACTURING, 485));
        $this->assertFalse(StructureTypes::securityAllows(0.5, IndustryActivity::MANUFACTURING, 30));

        // Everything else runs anywhere.
        $this->assertTrue(StructureTypes::securityAllows(1.0, IndustryActivity::MANUFACTURING, 334));
        $this->assertTrue(StructureTypes::securityAllows(1.0, IndustryActivity::COPYING));
    }
}
