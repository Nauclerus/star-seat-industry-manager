<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\RigAttributes;
use PHPUnit\Framework\TestCase;

/**
 * The rig attribute IDs confirmed against the live SDE, and the security-band
 * logic that selects which multiplier attribute applies.
 */
class RigAttributesTest extends TestCase
{
    public function test_confirmed_attribute_ids(): void
    {
        $this->assertSame(2593, RigAttributes::TE_BONUS_ATTRIBUTE);
        $this->assertSame(2594, RigAttributes::ME_BONUS_ATTRIBUTE);
        $this->assertSame(2595, RigAttributes::COST_BONUS_ATTRIBUTE);

        $this->assertTrue(RigAttributes::isConfigured());
    }

    public function test_multiplier_attribute_ids(): void
    {
        $this->assertSame(2355, RigAttributes::HIGHSEC_MULTIPLIER_ATTRIBUTE);
        $this->assertSame(2356, RigAttributes::LOWSEC_MULTIPLIER_ATTRIBUTE);
        $this->assertSame(2357, RigAttributes::NULLSEC_MULTIPLIER_ATTRIBUTE);
    }

    public function test_security_band_boundaries(): void
    {
        $this->assertSame('highsec', RigAttributes::securityBand(0.45));
        $this->assertSame('highsec', RigAttributes::securityBand(1.0));
        $this->assertSame('lowsec', RigAttributes::securityBand(0.44));
        $this->assertSame('lowsec', RigAttributes::securityBand(0.1));
        $this->assertSame('nullsec', RigAttributes::securityBand(0.0));
        $this->assertSame('nullsec', RigAttributes::securityBand(-1.0));
        $this->assertSame('unknown', RigAttributes::securityBand(null));
    }

    public function test_band_to_multiplier_attribute(): void
    {
        $this->assertSame(2355, RigAttributes::multiplierAttribute('highsec'));
        $this->assertSame(2356, RigAttributes::multiplierAttribute('lowsec'));
        $this->assertSame(2357, RigAttributes::multiplierAttribute('nullsec'));
        $this->assertNull(RigAttributes::multiplierAttribute('unknown'));
    }

    public function test_fallback_multipliers(): void
    {
        $this->assertSame(1.0, RigAttributes::fallbackMultiplier('highsec'));
        $this->assertSame(1.9, RigAttributes::fallbackMultiplier('lowsec'));
        $this->assertSame(2.1, RigAttributes::fallbackMultiplier('nullsec'));
        // An unknown band must never scale a bonus up.
        $this->assertSame(1.0, RigAttributes::fallbackMultiplier('unknown'));
    }

    /**
     * The worked example from the live SDE: a T1 ME rig at -2% reads 2% in
     * high-sec, 3.8% in low-sec and 4.2% in null-sec.
     */
    public function test_worked_example_across_bands(): void
    {
        $raw = -2.0;

        $this->assertSame(2.0, round(abs($raw) * RigAttributes::fallbackMultiplier('highsec'), 2));
        $this->assertSame(3.8, round(abs($raw) * RigAttributes::fallbackMultiplier('lowsec'), 2));
        $this->assertSame(4.2, round(abs($raw) * RigAttributes::fallbackMultiplier('nullsec'), 2));
    }
}
