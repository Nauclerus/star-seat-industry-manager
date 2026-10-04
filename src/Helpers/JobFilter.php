<?php

namespace IndustryManager\Helpers;

use Illuminate\Http\Request;

/**
 * JobFilter — the normalised, validated state of the jobs list filters.
 *
 * Everything arrives from the query string, so nothing here trusts it: unknown
 * scope tokens fall back to the default, non-numeric IDs are dropped, and an
 * empty search becomes null. That keeps the view, the service and the
 * "clear filters" link all working from the same object.
 *
 * Filtering itself is done in PHP against the normalised job arrays, which
 * keeps the semantics in one place and unit-testable without a database.
 */
class JobFilter
{
    /** Status values ESI reports for an industry job. */
    public const STATUSES = ['active', 'ready', 'paused', 'delivered', 'cancelled'];

    public string $scope;
    public ?int $activity;
    public ?string $status;
    public ?int $structure;
    public ?int $installer;
    public ?string $search;

    public function __construct(
        ?string $scope = null,
        ?int $activity = null,
        ?string $status = null,
        ?int $structure = null,
        ?int $installer = null,
        ?string $search = null
    ) {
        $this->scope = JobScope::normalize($scope);
        $this->activity = $activity;
        $this->status = in_array($status, self::STATUSES, true) ? $status : null;
        $this->structure = $structure;
        $this->installer = $installer;
        $this->search = self::cleanSearch($search);
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->query('scope'),
            self::positiveInt($request->query('activity')),
            is_string($request->query('status')) ? $request->query('status') : null,
            self::positiveInt($request->query('structure')),
            self::positiveInt($request->query('installer')),
            is_string($request->query('q')) ? $request->query('q') : null
        );
    }

    /**
     * Does a normalised job row pass every filter?
     */
    public function matches(array $job): bool
    {
        return $this->matchesScope($job) && $this->matchesTheRest($job);
    }

    /**
     * Every filter except the scope tab. Used to count how many jobs each
     * scope tab would show under the current activity/status/search filters.
     */
    public function matchesIgnoringScope(array $job): bool
    {
        return $this->matchesTheRest($job);
    }

    private function matchesScope(array $job): bool
    {
        return JobScope::matches($job['scopes'] ?? [], $this->scope);
    }

    private function matchesTheRest(array $job): bool
    {
        if ($this->activity !== null && (int) ($job['activity_id'] ?? 0) !== $this->activity) {
            return false;
        }

        if ($this->status !== null && ($job['status'] ?? null) !== $this->status) {
            return false;
        }

        if ($this->structure !== null && (int) ($job['facility_id'] ?? 0) !== $this->structure) {
            return false;
        }

        if ($this->installer !== null && (int) ($job['installer_id'] ?? 0) !== $this->installer) {
            return false;
        }

        if ($this->search !== null) {
            $haystack = mb_strtolower(implode(' ', [
                $job['blueprint_name'] ?? '',
                $job['product_name'] ?? '',
                $job['installer_name'] ?? '',
                $job['facility_name'] ?? '',
                $job['corporation_name'] ?? '',
                $job['system_name'] ?? '',
                $job['activity_name'] ?? '',
            ]));

            // The needle is lowercased at match time rather than in the
            // constructor, so the search box keeps whatever the user typed.
            if (! str_contains($haystack, mb_strtolower($this->search))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is anything other than the default scope selected?
     */
    public function hasRestrictions(): bool
    {
        return $this->activity !== null
            || $this->status !== null
            || $this->structure !== null
            || $this->installer !== null
            || $this->search !== null;
    }

    /**
     * Query-string parameters for this filter, omitting empty ones. Used to
     * build scope-tab links that preserve the other filters.
     *
     * @return array<string,string>
     */
    public function parameters(?string $scopeOverride = null): array
    {
        $params = ['scope' => $scopeOverride ?? $this->scope];

        if ($this->activity !== null) {
            $params['activity'] = (string) $this->activity;
        }
        if ($this->status !== null) {
            $params['status'] = (string) $this->status;
        }
        if ($this->structure !== null) {
            $params['structure'] = (string) $this->structure;
        }
        if ($this->installer !== null) {
            $params['installer'] = (string) $this->installer;
        }
        if ($this->search !== null) {
            $params['q'] = $this->search;
        }

        return $params;
    }

    private static function cleanSearch(?string $search): ?string
    {
        $search = is_string($search) ? trim($search) : null;

        return $search === '' ? null : $search;
    }

    private static function positiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
