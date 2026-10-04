<?php

namespace IndustryManager\Helpers;

/**
 * Decryptor — the eight invention decryptors and their modifiers, plus the
 * base outcome of an invented T2 BPC.
 *
 * Modifiers (stable CCP values):
 *   prob  = multiplier on invention success chance
 *   runs  = added to the invented BPC's licensed run count
 *   me    = added to the invented BPC's Material Efficiency
 *   te    = added to the invented BPC's Time Efficiency
 *
 * A vanilla invented BPC (no decryptor) is ME 2 / TE 4.
 */
class Decryptor
{
    public const BASE_ME = 2;
    public const BASE_TE = 4;

    /**
     * Keyed by a stable internal id (0 = none).
     */
    public const LIST = [
        0 => ['name' => 'No Decryptor', 'prob' => 1.0, 'runs' => 0, 'me' => 0, 'te' => 0],
        1 => ['name' => 'Accelerant Decryptor', 'prob' => 1.2, 'runs' => 1, 'me' => 2, 'te' => 10],
        2 => ['name' => 'Attainment Decryptor', 'prob' => 1.8, 'runs' => 4, 'me' => -1, 'te' => 4],
        3 => ['name' => 'Augmentation Decryptor', 'prob' => 0.6, 'runs' => 9, 'me' => -2, 'te' => 2],
        4 => ['name' => 'Optimized Attainment Decryptor', 'prob' => 1.9, 'runs' => 2, 'me' => 1, 'te' => -2],
        5 => ['name' => 'Optimized Augmentation Decryptor', 'prob' => 0.9, 'runs' => 7, 'me' => 2, 'te' => 0],
        6 => ['name' => 'Parity Decryptor', 'prob' => 1.5, 'runs' => 3, 'me' => 1, 'te' => -2],
        7 => ['name' => 'Process Decryptor', 'prob' => 1.1, 'runs' => 0, 'me' => 3, 'te' => 6],
        8 => ['name' => 'Symmetry Decryptor', 'prob' => 1.0, 'runs' => 2, 'me' => 1, 'te' => 8],
    ];

    /**
     * Skill multiplier at all level 5 (Encryption Methods + two datacore skills):
     *   1 + 5/40 + (5+5)/30 = 1.4583…
     * Exposed for the "skills at V" convenience toggle.
     */
    public const SKILL_MULTIPLIER_AT_V = 1.4583;
}
