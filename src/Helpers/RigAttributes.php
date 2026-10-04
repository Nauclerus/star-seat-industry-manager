<?php

namespace IndustryManager\Helpers;

/**
 * RigAttributes — the dogma attribute IDs that hold an industry rig's bonus
 * magnitudes, plus the security-band multiplier that scales them.
 *
 * Confirmed against the live SDE on 2026-10-03. SeAT v5 seeds dgmTypeAttributes
 * (values) but not dgmAttributeTypes (the human-readable name lookup), so these
 * were identified by their value distribution across rig variants — the
 * Diagnostic > Attribute Discovery tool, Step 5.
 *
 *   2593  Time Efficiency bonus        -20 / -24 %   (T1 / T2)
 *   2594  Material Efficiency bonus     -2 / -2.4 %   (T1 / T2)
 *   2595  Job Cost optimization        -10 / -12 %   (T1 / T2)
 *   2653  Thukker ME bonus             -3.7 %        (faction rigs only)
 *
 * A rig carries only its own bonus: the other attributes read 0 or are absent.
 * Thukker rigs are the exception that matters — they store their ME bonus in 2653
 * (`attributeThukkerEngRigMatBonus`) and leave 2594 absent, so reading only 2594
 * would score them as unrigged. So the resolver reads whichever bonus attribute is
 * non-zero rather than assuming a family, and a mixed fit is just the best
 * effective value per attribute.
 *
 * The security multiplier is NOT a constant — it is stored on the rig itself:
 *
 *   2355  high-sec multiplier
 *   2356  low-sec multiplier
 *   2357  null-sec / wormhole multiplier
 *
 * For a manufacturing rig those read 1.0 / 1.9 / 2.1, which is why a T1 ME rig
 * gives 2% in high-sec, 3.8% in low-sec and 4.2% in null-sec. Other families
 * carry their own values (1.06, 1.12, 1.2 and even 0.1 appear across the
 * Standup rigs), and moon drilling rigs are not security-scaled at all, so the
 * per-rig value is used whenever it is present and the classic table below is
 * only a fallback for installs where the attribute is missing.
 */
class RigAttributes
{
    public const TE_BONUS_ATTRIBUTE = 2593;
    public const ME_BONUS_ATTRIBUTE = 2594;
    public const COST_BONUS_ATTRIBUTE = 2595;

    /** Bonus attribute id => the label the UI uses for it. */
    public const BONUS_LABELS = [
        self::TE_BONUS_ATTRIBUTE => 'te',
        self::ME_BONUS_ATTRIBUTE => 'me',
        self::COST_BONUS_ATTRIBUTE => 'cost',
        // Thukker faction rigs carry their ME bonus here instead of 2594.
        self::THUKKER_ME_BONUS_ATTRIBUTE => 'me',
    ];

    /** Thukker ME bonus — `attributeThukkerEngRigMatBonus`. */
    public const THUKKER_ME_BONUS_ATTRIBUTE = 2653;

    /** Security-band multiplier attributes, read from the rig when present. */
    public const HIGHSEC_MULTIPLIER_ATTRIBUTE = 2355;
    public const LOWSEC_MULTIPLIER_ATTRIBUTE = 2356;
    public const NULLSEC_MULTIPLIER_ATTRIBUTE = 2357;

    /** Fallback used only when a rig carries no multiplier attribute. */
    public const FALLBACK_MULTIPLIER = [
        'highsec' => 1.0,
        'lowsec' => 1.9,
        'nullsec' => 2.1,
    ];

    /**
     * Are the bonus attribute IDs known? They are, so the calculator can apply
     * them. Kept as a gate so a future unconfirmed value degrades the UI rather
     * than silently producing wrong numbers.
     */
    public static function isConfigured(): bool
    {
        return self::ME_BONUS_ATTRIBUTE !== null;
    }

    /**
     * Security band for a structure's raw truesec (mapDenormalize.security).
     * >= 0.45 is high-sec, since CCP rounds 0.45+ up to a displayed 0.5.
     *
     * @return string  highsec | lowsec | nullsec | unknown
     */
    public static function securityBand(?float $security): string
    {
        if ($security === null) {
            return 'unknown';
        }

        if ($security >= 0.45) {
            return 'highsec';
        }

        return $security > 0.0 ? 'lowsec' : 'nullsec';
    }

    public static function securityClass(?float $security): string
    {
        return [
            'highsec' => 'High-sec',
            'lowsec' => 'Low-sec',
            'nullsec' => 'Null / WH',
            'unknown' => 'Unknown',
        ][self::securityBand($security)];
    }

    /**
     * The fallback multiplier for a band. Only used when the rig itself has no
     * multiplier attribute; the resolver prefers the value stored on the rig.
     */
    public static function fallbackMultiplier(string $band): float
    {
        return self::FALLBACK_MULTIPLIER[$band] ?? 1.0;
    }

    /**
     * Which multiplier attribute applies to a band. Null for an unknown band.
     */
    public static function multiplierAttribute(string $band): ?int
    {
        return [
            'highsec' => self::HIGHSEC_MULTIPLIER_ATTRIBUTE,
            'lowsec' => self::LOWSEC_MULTIPLIER_ATTRIBUTE,
            'nullsec' => self::NULLSEC_MULTIPLIER_ATTRIBUTE,
        ][$band] ?? null;
    }

    /**
     * Security multiplier for a band, from the fallback table. Prefer the value
     * read from the rig; this is what remains when it is absent.
     */
    public static function securityMultiplier(?float $security): float
    {
        return self::fallbackMultiplier(self::securityBand($security));
    }
}
