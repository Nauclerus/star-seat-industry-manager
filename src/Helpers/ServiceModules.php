<?php

namespace IndustryManager\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * ServiceModules — the restrictions CCP puts on the service modules themselves.
 *
 * A service module is only an industry capability while it is online, and whether
 * it can be online where the structure happens to sit is published as attributes on
 * the module type, which SeAT already syncs into `dgmTypeAttributes`:
 *
 *   2581 onlineMaxSecurityClass   0 = nullsec, 1 = lowsec, 2 = highsec
 *   1970 disallowInHighSec        1 = may not be activated in highsec
 *
 * So "reactions need security 0.4 or lower" is not a remembered rule here: the
 * Standup Composite, Polymer and Biochemical Reactors each carry 2581 = 1 and
 * 1970 = 1, and highsec starts at 0.5, so the highest security they can be onlined
 * in is 0.4. The Standup Capital Shipyard I carries the same pair, which is why
 * capital ships cannot be built in highsec, and the Standup Supercapital Shipyard I
 * carries 2581 = 1 on top of being fitable only in a Sotiyo.
 *
 * A module without either attribute has no security restriction, and a module whose
 * attributes cannot be read is treated as unrestricted rather than blocked: the
 * capability gate still has to decide on the fitted services, and inventing a
 * restriction the data does not publish would hide structures that work.
 */
class ServiceModules
{
    public const ONLINE_MAX_SECURITY_CLASS = 2581;

    public const DISALLOW_IN_HIGHSEC = 1970;

    /**
     * The highest security value each published security class allows.
     *
     * null means the class is the top of the range: no restriction.
     */
    public const CLASS_MAX_SECURITY = [
        0 => 0.0,   // nullsec only
        1 => 0.4,   // lowsec and nullsec
        2 => null,  // highsec allowed
    ];

    /** @var array<int, ?float>  typeID => highest security the module can be onlined in */
    private static ?array $memo = null;

    /** @var bool */
    private static bool $readFailed = false;

    /**
     * The highest system security this service module can be onlined in, or null
     * when it has no security restriction.
     *
     * Read per type and memoised: only the modules actually fitted in a structure
     * are ever asked about, so this is a handful of indexed lookups per request.
     */
    public static function maxSecurity(int $typeId): ?float
    {
        self::$memo ??= [];

        if (array_key_exists($typeId, self::$memo)) {
            return self::$memo[$typeId];
        }

        return self::$memo[$typeId] = self::read($typeId);
    }

    /**
     * Is this module allowed to be online in a system of this security?
     *
     * An unknown security is not a permission, matching StructureTypes: only a
     * known value low enough counts as allowed.
     */
    public static function securityAllows(int $typeId, ?float $security): bool
    {
        $max = self::maxSecurity($typeId);

        if ($max === null) {
            return true;
        }

        return $security !== null && $security <= $max;
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$readFailed = false;
    }

    /**
     * @return array<int, ?float>
     */
    private static function read(int $typeId): ?float
    {
        if (self::$readFailed || !IndustryData::hasTable('dgmTypeAttributes')) {
            return null;
        }

        try {
            $rows = DB::table('dgmTypeAttributes')
                ->where('typeID', $typeId)
                ->whereIn('attributeID', [self::ONLINE_MAX_SECURITY_CLASS, self::DISALLOW_IN_HIGHSEC])
                ->get(['attributeID', 'valueInt', 'valueFloat']);
        } catch (\Throwable $e) {
            self::$readFailed = true;

            return null;
        }

        $attributes = [];

        foreach ($rows as $row) {
            $attributes[(int) $row->attributeID] = self::number($row);
        }

        return self::maxSecurityFrom($attributes);
    }

    /**
     * @param  array<int, float>  $attributes
     */
    private static function maxSecurityFrom(array $attributes): ?float
    {
        $class = $attributes[self::ONLINE_MAX_SECURITY_CLASS] ?? null;

        if ($class !== null) {
            $class = (int) $class;

            if (array_key_exists($class, self::CLASS_MAX_SECURITY)) {
                return self::CLASS_MAX_SECURITY[$class];
            }
        }

        // disallowInHighSec on its own means the same thing as the lowsec class.
        if (($attributes[self::DISALLOW_IN_HIGHSEC] ?? 0.0) >= 1.0) {
            return self::CLASS_MAX_SECURITY[1];
        }

        return null;
    }

    private static function number(object $row): float
    {
        if ($row->valueFloat !== null) {
            return (float) $row->valueFloat;
        }

        return (float) ($row->valueInt ?? 0);
    }
}
