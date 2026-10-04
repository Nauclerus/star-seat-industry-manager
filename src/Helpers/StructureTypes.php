<?php

namespace IndustryManager\Helpers;

/**
 * StructureTypes — the Upwell structures that can host industry, by type ID.
 *
 * Engineering Complexes (Raitaru M, Azbel L, Sotiyo XL), Refineries (Athanor M,
 * Tatara L) and Citadels (Astrahus M, Fortizar L, Keepstar XL).
 *
 * What each of them can actually run is not a property of the type: it is the
 * service module fitted in its service slots, which the capability tables read.
 * This class is the fallback for when those tables are not populated, and the
 * naming/size layer for the structures page either way.
 *
 * The fallback follows the canFitShipGroup and canFitShipType attributes CCP
 * publishes on the service modules, so it knows which structures could host which
 * service. What it cannot know in that path is which service is actually fitted,
 * which is why the structures page keeps listing the industry structures rather
 * than every structure with service slots.
 *
 * Type IDs are stable CCP constants. The named Fortizars are the YC120 outpost
 * migration structures: no longer obtainable, and they publish a cost and time
 * bonus but no material bonus.
 */
class StructureTypes
{
    public const RAITARU = 35825;
    public const AZBEL = 35826;
    public const SOTIYO = 35827;
    public const ATHANOR = 35835;
    public const TATARA = 35836;

    public const ASTRAHUS = 35832;
    public const FORTIZAR = 35833;
    public const KEEPSTAR = 35834;

    public const FORTIZAR_MOREAU = 47512;
    public const FORTIZAR_DRACCOUS = 47513;
    public const FORTIZAR_HORIZON = 47514;

    public const ENGINEERING_COMPLEXES = [self::RAITARU, self::AZBEL, self::SOTIYO];
    public const REFINERIES = [self::ATHANOR, self::TATARA];
    public const CITADELS = [self::ASTRAHUS, self::FORTIZAR, self::KEEPSTAR];
    public const NAMED_FORTIZARS = [self::FORTIZAR_MOREAU, self::FORTIZAR_DRACCOUS, self::FORTIZAR_HORIZON];

    /** Curated fallback for what runs industry without capability data. */
    public const INDUSTRY = [self::RAITARU, self::AZBEL, self::SOTIYO, self::ATHANOR, self::TATARA];

    /** Everything that has service slots an industry module can occupy. */
    public const SERVICE_HOSTS = [
        self::RAITARU, self::AZBEL, self::SOTIYO,
        self::ATHANOR, self::TATARA,
        self::ASTRAHUS, self::FORTIZAR, self::KEEPSTAR,
        self::FORTIZAR_MOREAU, self::FORTIZAR_DRACCOUS, self::FORTIZAR_HORIZON,
    ];

    /**
     * Reactions happen in security 0.4 or lower.
     *
     * The reactor service modules publish this themselves: each of the Standup
     * Composite, Polymer and Biochemical Reactors carries onlineMaxSecurityClass (2581)
     * = 1 (lowsec) and disallowInHighSec (1970) = 1, and highsec starts at 0.5.
     * ServiceModules reads those values; this constant is what stands in when the
     * attributes cannot be read. It is corroborated from the side as well: the
     * reaction rigs publish a lowsec (2356) and nullsec (2357) multiplier and no
     * highsec (2355) one, so there is no such thing as a highsec reaction bonus to fit.
     */
    public const REACTION_MAX_SECURITY = 0.4;

    /**
     * The same limit for the capital and supercapital shipyards, which publish the
     * identical pair of attributes on their service modules.
     */
    public const CAPITAL_MAX_SECURITY = 0.4;

    /** Groups of the products only a Capital or Supercapital Shipyard accepts. */
    public const CAPITAL_SHIP_GROUPS = [485, 547, 883, 1538, 4594, 5120];
    public const SUPERCAPITAL_SHIP_GROUPS = [30, 659];

    /**
     * Which structures the Capital Shipyard service module can be fitted in, from
     * its canFitShipType attributes: the L and XL engineering complexes and the L
     * and XL citadels. Not a Raitaru, not an Astrahus, not a refinery.
     */
    public const CAPITAL_SHIPYARD_HOSTS = [
        self::SOTIYO, self::AZBEL,
        self::KEEPSTAR, self::FORTIZAR,
        self::FORTIZAR_MOREAU, self::FORTIZAR_DRACCOUS, self::FORTIZAR_HORIZON,
    ];

    /** The Supercapital Shipyard fits a Sotiyo and nothing else. */
    public const SUPERCAPITAL_SHIPYARD_HOSTS = [self::SOTIYO];

    public static function name(int $typeId): string
    {
        return [
            self::RAITARU => 'Raitaru',
            self::AZBEL => 'Azbel',
            self::SOTIYO => 'Sotiyo',
            self::ATHANOR => 'Athanor',
            self::TATARA => 'Tatara',
            self::ASTRAHUS => 'Astrahus',
            self::FORTIZAR => 'Fortizar',
            self::KEEPSTAR => 'Keepstar',
            self::FORTIZAR_MOREAU => 'Fortizar "Moreau"',
            self::FORTIZAR_DRACCOUS => 'Fortizar "Draccous"',
            self::FORTIZAR_HORIZON => 'Fortizar "Horizon"',
        ][$typeId] ?? ('Structure #' . $typeId);
    }

    public static function className(int $typeId): string
    {
        if (in_array($typeId, self::ENGINEERING_COMPLEXES, true)) {
            return 'Engineering Complex';
        }

        if (in_array($typeId, self::REFINERIES, true)) {
            return 'Refinery';
        }

        if (in_array($typeId, self::CITADELS, true) || in_array($typeId, self::NAMED_FORTIZARS, true)) {
            return 'Citadel';
        }

        return 'Structure';
    }

    public static function sizeClass(int $typeId): string
    {
        return [
            self::RAITARU => 'M',
            self::AZBEL => 'L',
            self::SOTIYO => 'XL',
            self::ATHANOR => 'M',
            self::TATARA => 'L',
            self::ASTRAHUS => 'M',
            self::FORTIZAR => 'L',
            self::KEEPSTAR => 'XL',
            self::FORTIZAR_MOREAU => 'L',
            self::FORTIZAR_DRACCOUS => 'L',
            self::FORTIZAR_HORIZON => 'L',
        ][$typeId] ?? '?';
    }

    /**
     * The curated fallback gate, used only when the capability tables are empty:
     * which structure types are able to host the service module an activity needs.
     *
     * The split follows the canFitShipGroup/canFitShipType attributes CCP publishes
     * on the service modules themselves: the manufacturing, copying, invention and
     * research modules fit citadels, engineering complexes and refineries alike; the
     * capital shipyard fits only the L and XL complexes and citadels; the
     * supercapital shipyard fits a Sotiyo only; and the reactors fit refineries only.
     *
     * What is *fitted* is still unknown in this path, which is why the capability
     * tables are preferred and this is only the stand-in.
     */
    public static function fallbackAllows(int $typeId, int $activityId, ?int $productGroupId = null): bool
    {
        if ($activityId === IndustryActivity::REACTIONS) {
            return in_array($typeId, self::REFINERIES, true);
        }

        if ($productGroupId !== null && in_array($productGroupId, self::SUPERCAPITAL_SHIP_GROUPS, true)) {
            return in_array($typeId, self::SUPERCAPITAL_SHIPYARD_HOSTS, true);
        }

        if ($productGroupId !== null && in_array($productGroupId, self::CAPITAL_SHIP_GROUPS, true)) {
            return in_array($typeId, self::CAPITAL_SHIPYARD_HOSTS, true);
        }

        return in_array($typeId, self::SERVICE_HOSTS, true);
    }

    /**
     * The security a run needs, for the curated path: reactions in 0.4 or lower,
     * capital and supercapital builds in 0.4 or lower, everything else anywhere.
     *
     * The capability path reads the same limits off the service modules instead.
     */
    public static function securityAllows(?float $security, int $activityId, ?int $productGroupId = null): bool
    {
        $max = null;

        if ($activityId === IndustryActivity::REACTIONS) {
            $max = self::REACTION_MAX_SECURITY;
        } elseif ($productGroupId !== null
            && (in_array($productGroupId, self::CAPITAL_SHIP_GROUPS, true)
                || in_array($productGroupId, self::SUPERCAPITAL_SHIP_GROUPS, true))) {
            $max = self::CAPITAL_MAX_SECURITY;
        }

        if ($max === null) {
            return true;
        }

        // An unknown security is not a permission: only a known low enough one is.
        return $security !== null && $security <= $max;
    }
}
