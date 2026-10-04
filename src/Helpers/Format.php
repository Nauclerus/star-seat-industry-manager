<?php

namespace IndustryManager\Helpers;

/**
 * Format — small presentation helpers shared across views.
 */
class Format
{
    /**
     * Human EVE-style duration from seconds, e.g. 93600 -> "1d 2h".
     * Shows seconds only for sub-minute durations.
     */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $d = intdiv($seconds, 86400);
        $seconds %= 86400;
        $h = intdiv($seconds, 3600);
        $seconds %= 3600;
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        $parts = [];
        if ($d) {
            $parts[] = $d . 'd';
        }
        if ($h) {
            $parts[] = $h . 'h';
        }
        if ($m) {
            $parts[] = $m . 'm';
        }
        if ($s && ! $d && ! $h) {
            $parts[] = $s . 's';
        }

        return implode(' ', $parts) ?: '0s';
    }
}
