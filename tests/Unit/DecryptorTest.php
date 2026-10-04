<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\Decryptor;
use PHPUnit\Framework\TestCase;

/**
 * The eight invention decryptors and their stable modifiers.
 */
class DecryptorTest extends TestCase
{
    public function test_none_entry_is_neutral(): void
    {
        $none = Decryptor::LIST[0];

        $this->assertSame(1.0, $none['prob']);
        $this->assertSame(0, $none['runs']);
        $this->assertSame(0, $none['me']);
        $this->assertSame(0, $none['te']);
    }

    public function test_eight_decryptors_plus_none(): void
    {
        $this->assertCount(9, Decryptor::LIST);
    }

    public function test_known_values(): void
    {
        // Accelerant: +20% probability, +1 run, +2 ME, +10 TE.
        $this->assertSame(['name' => 'Accelerant Decryptor', 'prob' => 1.2, 'runs' => 1, 'me' => 2, 'te' => 10], Decryptor::LIST[1]);

        // Attainment: the highest probability multiplier.
        $this->assertSame(1.8, Decryptor::LIST[2]['prob']);

        // Optimized Attainment has the highest success chance of all.
        $this->assertSame(1.9, Decryptor::LIST[4]['prob']);
    }

    public function test_base_invented_bpc_values(): void
    {
        $this->assertSame(2, Decryptor::BASE_ME);
        $this->assertSame(4, Decryptor::BASE_TE);
    }

    public function test_skill_multiplier_at_v(): void
    {
        // 1 + 5/40 + (5+5)/30 = 1.4583...
        $this->assertEqualsWithDelta(1.4583, Decryptor::SKILL_MULTIPLIER_AT_V, 0.0001);
    }
}
