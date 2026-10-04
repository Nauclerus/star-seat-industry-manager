<?php

namespace IndustryManager\Helpers;

/**
 * StructureTypes — the Upwell structures that run industry, by type ID.
 *
 * Engineering Complexes (manufacturing, invention, copying, research):
 *   Raitaru (M), Azbel (L), Sotiyo (XL)
 * Refineries (reactions, reprocessing, moon drilling):
 *   Athanor (M), Tatara (L)
 *
 * Type IDs are stable CCP constants.
 */
class StructureTypes
{
    public const RAITARU = 35825;
    public const AZBEL = 35826;
    public const SOTIYO = 35827;
    public const ATHANOR = 35835;
    public const TATARA = 35836;

    public const ENGINEERING_COMPLEXES = [self::RAITARU, self::AZBEL, self::SOTIYO];
    public const REFINERIES = [self::ATHANOR, self::TATARA];
    public const INDUSTRY = [self::RAITARU, self::AZBEL, self::SOTIYO, self::ATHANOR, self::TATARA];

    public static function name(int $typeId): string
    {
        return [
            self::RAITARU => 'Raitaru',
            self::AZBEL => 'Azbel',
            self::SOTIYO => 'Sotiyo',
            self::ATHANOR => 'Athanor',
            self::TATARA => 'Tatara',
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
        ][$typeId] ?? '?';
    }
}
