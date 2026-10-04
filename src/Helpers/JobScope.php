<?php

namespace IndustryManager\Helpers;

/**
 * JobScope — the "who does this job belong to?" axis of the jobs list.
 *
 * Mirrors the personal/corporation split of EVE's industry window and adds
 * `corpmates`, which is the question people actually ask: "what is my
 * corporation building that I did not start myself?".
 *
 * A single job can belong to several scopes at once: a job you installed in
 * your own corporation's engineering facility is both `personal` and
 * `corporation`. Scopes are therefore membership tags on the job, and the UI
 * filter picks one tag to show.
 *
 * Membership is decided from two ID sets resolved per user:
 *   character_ids     — the user's linked characters
 *   corporation_ids   — the corporations of those characters
 *
 * Nothing here reaches past the user's own corporations. Alliance-wide
 * industry is deliberately out of scope: ESI has no alliance industry-jobs
 * endpoint, so any such rows in the database belong to corporations some other
 * user chose to sync, and this board does not surface them.
 *
 * Pure: no database, no request, no config. Unit-testable on its own.
 */
class JobScope
{
    /** Jobs belonging to, or installed by, one of the user's own characters. */
    public const PERSONAL = 'personal';

    /** Industry run by one of the user's own corporations. */
    public const CORPORATION = 'corporation';

    /** Corporation industry the user did not install personally. */
    public const CORPMATES = 'corpmates';

    /** Everything the user is entitled to see. */
    public const ALL = 'all';

    /** Source table a job row came from. */
    public const SOURCE_CHARACTER = 'character';
    public const SOURCE_CORPORATION = 'corporation';

    /**
     * Display order for the filter tabs.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::ALL,
            self::PERSONAL,
            self::CORPORATION,
            self::CORPMATES,
        ];
    }

    /**
     * Scopes a job row can actually be tagged with. `all` is a filter, not a
     * membership, so it is deliberately absent.
     *
     * @return string[]
     */
    public static function memberships(): array
    {
        return [
            self::PERSONAL,
            self::CORPORATION,
            self::CORPMATES,
        ];
    }

    public static function default(): string
    {
        return self::ALL;
    }

    public static function isKnown(?string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * Coerce untrusted input to a scope token, falling back to the default.
     */
    public static function normalize(?string $scope): string
    {
        $scope = is_string($scope) ? strtolower(trim($scope)) : null;

        return self::isKnown($scope) ? $scope : self::default();
    }

    public static function label(string $scope): string
    {
        return [
            self::ALL => 'All',
            self::PERSONAL => 'Personal',
            self::CORPORATION => 'Corporation',
            self::CORPMATES => 'Corpmates',
        ][$scope] ?? ucfirst($scope);
    }

    public static function icon(string $scope): string
    {
        return [
            self::ALL => 'fas fa-layer-group',
            self::PERSONAL => 'fas fa-user',
            self::CORPORATION => 'fas fa-building',
            self::CORPMATES => 'fas fa-users',
        ][$scope] ?? 'fas fa-filter';
    }

    /**
     * Short human explanation of a scope, shown under the tabs.
     */
    public static function description(string $scope): string
    {
        return [
            self::ALL => 'Everything your linked characters and your corporations are running.',
            self::PERSONAL => 'Jobs from every character linked to your account, including the ones you installed in corporation structures.',
            self::CORPORATION => 'All industry run by your characters\' corporations, whoever installed it.',
            self::CORPMATES => 'Corporation industry installed by someone other than your own characters.',
        ][$scope] ?? '';
    }

    /**
     * Which scopes a job belongs to.
     *
     * @param  string  $ownerType  self::SOURCE_CHARACTER or self::SOURCE_CORPORATION
     * @param  int     $ownerId    character_id or corporation_id of the job owner
     * @param  int     $installerId
     * @param  array{character_ids:array,corporation_ids:array} $context
     * @return string[]
     */
    public static function scopesFor(string $ownerType, int $ownerId, int $installerId, array $context): array
    {
        $mine = $context['character_ids'] ?? [];
        $corps = $context['corporation_ids'] ?? [];

        // A character-industry job is by definition that character's own
        // project; it is not corporation property.
        if ($ownerType === self::SOURCE_CHARACTER) {
            return in_array($ownerId, $mine, true) ? [self::PERSONAL] : [];
        }

        // Corporation jobs are only visible when the corporation is one of the
        // user's own. Anything else is outside this board.
        if (! in_array($ownerId, $corps, true)) {
            return [];
        }

        $scopes = [self::CORPORATION];

        if (in_array($installerId, $mine, true)) {
            // Installed by one of my characters: it is my project too.
            array_unshift($scopes, self::PERSONAL);
        } else {
            $scopes[] = self::CORPMATES;
        }

        return $scopes;
    }

    /**
     * Does a job tagged with $scopes satisfy the $scope filter?
     *
     * @param  string[] $scopes
     */
    public static function matches(array $scopes, string $scope): bool
    {
        // A row belonging to no scope at all is outside the user's entitlement,
        // so even the `all` tab must not show it.
        if (empty($scopes)) {
            return false;
        }

        if ($scope === self::ALL) {
            return true;
        }

        return in_array($scope, $scopes, true);
    }
}
