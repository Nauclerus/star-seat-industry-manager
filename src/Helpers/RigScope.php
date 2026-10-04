<?php

namespace IndustryManager\Helpers;

/**
 * RigScope — which jobs a fitted rig actually applies to.
 *
 * The rig side is fully data-driven. Every manufacturing rig has one dogma effect
 * per scope it covers, and that effect writes the rig's bonus into a scope-specific
 * structure multiplier attribute:
 *
 *   rigAdvComponentManufactureMaterialBonus  -> 2557
 *   rigBasCapCompManufactureMaterialBonus    -> 2559
 *   rigStructureManufactureMaterialBonus     -> 2561
 *   rigAdvCapComponentManufactureMaterialBonus -> 2658
 *
 * So `dgmTypeEffects` (core, seeded) + the effect definitions (the plugin's own
 * `industry_manager_effects` table) give the exact set of attributes a fitted rig
 * writes. A rig applies to a job when the job's multiplier attribute is in that
 * set — which is also why rigs do not stack: only one rig can write a given
 * attribute.
 *
 * What the SDE does NOT contain is the reverse join: which blueprint products
 * belong to a scope. CCP publishes the scope names but not the membership, so the
 * product side is game logic. The maps below are the curated part, kept as group
 * IDs (stable SDE constants) so they are auditable and testable rather than
 * string-parsed.
 */
final class RigScope
{
    /** No scope: the job is not covered by any manufacturing rig. */
    public const NONE = 'none';

    // Non-ship scopes.
    public const EQUIPMENT = 'equipment';
    public const AMMO = 'ammo';
    public const DRONE = 'drone';
    public const STRUCTURE = 'structure';

    // Component scopes.
    public const ADV_COMPONENT = 'adv_component';
    public const BAS_CAP_COMP = 'bas_cap_comp';
    public const ADV_CAP_COMP = 'adv_cap_comp';

    // Ship scopes, split by size and by tech level.
    public const BAS_SMALL_SHIP = 'bas_small_ship';
    public const BAS_MEDIUM_SHIP = 'bas_medium_ship';
    public const BAS_LARGE_SHIP = 'bas_large_ship';
    public const ADV_SMALL_SHIP = 'adv_small_ship';
    public const ADV_MEDIUM_SHIP = 'adv_medium_ship';
    public const ADV_LARGE_SHIP = 'adv_large_ship';
    public const CAP_SHIP = 'cap_ship';

    /** A rig token that covers every ship scope. */
    public const ALL_SHIP = 'all_ship';

    // Reaction scopes — these come straight from the product's group, so they are
    // data-driven rather than curated.
    public const REACTION_CHEMICAL = 'reaction_chemical';
    public const REACTION_BIO = 'reaction_bio';
    public const REACTION_HYBRID = 'reaction_hybrid';

    /**
     * Scope token => [material-or-cost multiplier attribute, time multiplier
     * attribute]. These are the structure attributes the game reads for a job.
     * Confirmed against CCP's dogmaEffects.jsonl on 2026-10-04.
     */
    public const MULTIPLIERS = [
        self::EQUIPMENT => [2538, 2539],
        self::AMMO => [2540, 2541],
        self::DRONE => [2542, 2543],

        self::BAS_SMALL_SHIP => [2544, 2545],
        self::BAS_MEDIUM_SHIP => [2546, 2547],
        self::BAS_LARGE_SHIP => [2548, 2549],

        self::ADV_SMALL_SHIP => [2550, 2551],
        self::ADV_MEDIUM_SHIP => [2552, 2553],
        self::ADV_LARGE_SHIP => [2555, 2556],

        self::ADV_COMPONENT => [2557, 2558],
        self::BAS_CAP_COMP => [2559, 2560],
        self::ADV_CAP_COMP => [2658, 2659],

        self::STRUCTURE => [2561, 2562],
        self::CAP_SHIP => [2575, 2576],
        self::ALL_SHIP => [2592, 2591],

        self::REACTION_CHEMICAL => [2718, 2717],
        self::REACTION_BIO => [2720, 2719],
        self::REACTION_HYBRID => [2716, 2715],
    ];

    /**
     * Activities whose bonus is not split by scope: the multiplier attribute is
     * fixed per activity. Confirmed from the same effect definitions.
     */
    public const ACTIVITY_MULTIPLIERS = [
        IndustryActivity::INVENTION => [2563, 2564],   // invention cost, invention time
        IndustryActivity::RESEARCH_ME => [2565, 2566], // ME research cost, ME research time
        IndustryActivity::RESEARCH_TE => [2567, 2568], // TE research cost, TE research time
        IndustryActivity::COPYING => [2569, 2570],     // copy cost, copy time
    ];

    /**
     * Curated product-side map: inventory group => scope token.
     *
     * This is the part CCP does not publish. Group IDs are stable SDE constants.
     * Verified against EVE Ref's per-rig "affected groups" lists on 2026-10-04.
     */
    public const GROUP_SCOPES = [
        // Non-capital components are all covered by the advanced component scope.
        332 => self::ADV_COMPONENT,   // Tool
        334 => self::ADV_COMPONENT,   // Construction Components
        716 => self::ADV_COMPONENT,   // Data Interfaces
        964 => self::ADV_COMPONENT,   // Hybrid Tech Components

        873 => self::BAS_CAP_COMP,    // Capital Construction Components
        913 => self::ADV_CAP_COMP,    // Advanced Capital Construction Components

        536 => self::STRUCTURE,       // Structure Components

        // Reaction groups — data-driven, listed here for completeness.
        436 => self::REACTION_CHEMICAL, // Simple Reaction
        484 => self::REACTION_CHEMICAL, // Complex Reactions
        661 => self::REACTION_BIO,      // Simple Biochemical Reactions
        662 => self::REACTION_BIO,      // Complex Biochemical Reactions
        977 => self::REACTION_HYBRID,   // Hybrid Reactions
    ];

    /** Inventory categories that map straight to a scope. */
    public const CATEGORY_SCOPES = [
        'Charge' => self::AMMO,
        'Drone' => self::DRONE,
        'Module' => self::EQUIPMENT,
        'Structure' => self::STRUCTURE,
    ];

    /**
     * Ship groups by size class. Anything in the Ship category not listed here is
     * a capital ship. Group names are the SDE's own English group names.
     */
    public const SMALL_SHIP_GROUPS = [
        'frigate', 'destroyer', 'corvette', 'shuttle', 'capsule',
        'assault frigate', 'interceptor', 'stealth bomber', 'covert ops',
        'logistics', 'logistics frigate', 'command destroyer', 'tactical destroyer',
        'expedition frigate', 'blockade runner',
    ];

    public const MEDIUM_SHIP_GROUPS = [
        'cruiser', 'combat battlecruiser', 'battlecruiser', 'heavy assault cruiser',
        'attack battlecruiser', 'strategic cruiser', 'heavy interdiction cruiser',
        'flag cruiser',
    ];

    public const LARGE_SHIP_GROUPS = [
        'battleship', 'elite battleship', 'combat recon ship',
        'electronic attack ship', 'marauder',
    ];

    /**
     * The multiplier attributes a job reads: [material-or-cost, time].
     *
     * @return array{0:int,1:int}|null
     */
    public static function multiplierAttributes(int $activityId, string $scope): ?array
    {
        // Manufacturing and reactions are both chosen by what is produced: the
        // game reads the multiplier attribute of the product's scope. Reactions
        // have their own set per reaction family (rigReactionCompMatBonus writes
        // 2718, rigReactionBioTimeBonus writes 2719), so a manufacturing rig for
        // one scope must not carry over to a reaction job.
        $byScope = $activityId === IndustryActivity::MANUFACTURING
            || $activityId === IndustryActivity::REACTIONS;

        if ($byScope) {
            return self::MULTIPLIERS[$scope] ?? null;
        }

        return self::ACTIVITY_MULTIPLIERS[$activityId] ?? null;
    }

    /**
     * Canonical scope token from a dogma effect name, e.g.
     * `rigAdvComponentManufactureMaterialBonus` => `adv_component`.
     *
     * The effect name is authoritative: it is what CCP publishes and it is the
     * same on both import paths.
     */
    public static function tokenFromEffectName(string $effectName): ?string
    {
        // Reaction effects are named per reaction family, not per ship scope:
        // rigReactionBioMatBonus, rigReactionCompTimeBonus, rigReactionHybTimeBonus.
        if (preg_match('/^rigReaction([A-Za-z]+)(?:Mat|Time)Bonus$/', $effectName, $m) === 1) {
            return [
                'bio' => self::REACTION_BIO,
                'comp' => self::REACTION_CHEMICAL,
                'hyb' => self::REACTION_HYBRID,
            ][strtolower($m[1])] ?? null;
        }

        // Everything else is rig[Thukker]<scope>Manufacture(Material|Time)Bonus.
        if (preg_match('/^rig(?:Thukker)?([A-Za-z]+)Manufacture(?:Material|Time)Bonus$/', $effectName, $m) === 1) {
            return self::tokenFromShipOrScopeToken(strtolower($m[1]));
        }

        return null;
    }

    /**
     * Does a rig's scope cover a product's scope?
     *
     * `all_ship` is the only rig scope that spans several product scopes.
     */
    public static function applies(?string $rigScope, ?string $productScope): bool
    {
        if ($rigScope === null || $productScope === null || $productScope === self::NONE) {
            return false;
        }

        if ($rigScope === $productScope) {
            return true;
        }

        return $rigScope === self::ALL_SHIP && str_ends_with($productScope, '_ship');
    }

    /**
     * Scope token for a blueprint product.
     *
     * @param  int|null     $groupId       invTypes.groupID
     * @param  string|null  $groupName     invGroups.groupName (needed for ship size)
     * @param  string|null  $categoryName  invCategories.categoryName
     * @param  int|null     $techLevel     invTypes.techLevel (1 = T1, 2 = T2)
     */
    public static function tokenForProduct(?int $groupId, ?string $groupName, ?string $categoryName, ?int $techLevel): string
    {
        // Group-level rules win: they are exact and cover the component and
        // reaction scopes.
        if ($groupId !== null && isset(self::GROUP_SCOPES[$groupId])) {
            return self::GROUP_SCOPES[$groupId];
        }

        if ($categoryName === 'Ship' && $groupName !== null) {
            $size = self::shipSize(strtolower(trim($groupName)));

            if ($size === 'capital') {
                return self::CAP_SHIP;
            }

            return ($techLevel !== null && $techLevel >= 2 ? 'adv_' : 'bas_') . $size . '_ship';
        }

        return $categoryName !== null ? (self::CATEGORY_SCOPES[$categoryName] ?? self::NONE) : self::NONE;
    }

    /**
     * Attribute id -> scope token, for the attributes the plugin's effect rows
     * point at. Kept so a resolver can name the scope it matched.
     */
    public static function tokenForAttribute(int $attributeId): ?string
    {
        foreach (self::MULTIPLIERS as $scope => $pair) {
            if ($pair[0] === $attributeId || $pair[1] === $attributeId) {
                return $scope;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * Effect-name fragment -> canonical scope token.
     */
    private static function tokenFromShipOrScopeToken(string $fragment): ?string
    {
        $map = [
            'smallship' => self::BAS_SMALL_SHIP,
            'mediumship' => self::BAS_MEDIUM_SHIP,
            'mediumships' => self::BAS_MEDIUM_SHIP,
            'largeship' => self::BAS_LARGE_SHIP,
            'advsmship' => self::ADV_SMALL_SHIP,
            'advmedship' => self::ADV_MEDIUM_SHIP,
            'advlarship' => self::ADV_LARGE_SHIP,
            'capship' => self::CAP_SHIP,
            'allship' => self::ALL_SHIP,
            'advcomponent' => self::ADV_COMPONENT,
            'advcapcomponent' => self::ADV_CAP_COMP,
            'bascapcomp' => self::BAS_CAP_COMP,
            'structure' => self::STRUCTURE,
            'ammo' => self::AMMO,
            'drone' => self::DRONE,
            'equipment' => self::EQUIPMENT,
        ];

        return $map[$fragment] ?? null;
    }

    private static function shipSize(string $group): string
    {
        if (in_array($group, self::SMALL_SHIP_GROUPS, true)) {
            return 'small';
        }

        if (in_array($group, self::MEDIUM_SHIP_GROUPS, true)) {
            return 'medium';
        }

        if (in_array($group, self::LARGE_SHIP_GROUPS, true)) {
            return 'large';
        }

        return 'capital';
    }
}
