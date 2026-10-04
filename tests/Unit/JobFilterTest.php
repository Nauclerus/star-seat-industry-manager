<?php

namespace IndustryManager\Tests\Unit;

use Illuminate\Http\Request;
use IndustryManager\Helpers\JobFilter;
use IndustryManager\Helpers\JobScope;
use PHPUnit\Framework\TestCase;

/**
 * Filter state and matching. The rows here are the normalised arrays
 * JobsService produces, so the shape is part of what is being pinned.
 */
class JobFilterTest extends TestCase
{
    private function job(array $overrides = []): array
    {
        return array_merge([
            'job_id' => 1,
            'owner_type' => JobScope::SOURCE_CORPORATION,
            'owner_id' => 2001,
            'scopes' => [JobScope::PERSONAL, JobScope::CORPORATION],
            'activity_id' => 1,
            'activity_name' => 'Manufacturing',
            'activity_icon' => 'fas fa-cogs',
            'blueprint_name' => 'Assault Frigate Blueprint (Allos)',
            'product_name' => 'Allos',
            'runs' => 1,
            'status' => 'active',
            'start_date' => '2026-10-01 12:00:00',
            'end_date' => '2026-10-02 12:00:00',
            'installer_id' => 1001,
            'installer_name' => 'Wolf Vdz',
            'facility_id' => 1052778927163,
            'facility_name' => '6Z-CKS - Raptor Werks',
            'system_name' => '6Z-CKS',
            'corporation_name' => 'Star Alliance',
        ], $overrides);
    }

    public function test_an_empty_filter_matches_everything_the_user_can_see(): void
    {
        $filter = new JobFilter();

        $this->assertSame(JobScope::ALL, $filter->scope);
        $this->assertFalse($filter->hasRestrictions());
        $this->assertTrue($filter->matches($this->job()));
        $this->assertTrue($filter->matches($this->job(['scopes' => [JobScope::CORPORATION, JobScope::CORPMATES]])));
        $this->assertFalse($filter->matches($this->job(['scopes' => []])));
    }

    public function test_unknown_status_is_dropped_but_valid_ones_are_kept(): void
    {
        $this->assertNull((new JobFilter(null, null, 'not-a-status'))->status);
        $this->assertNull((new JobFilter(null, null, null))->status);

        foreach (JobFilter::STATUSES as $status) {
            $this->assertSame($status, (new JobFilter(null, null, $status))->status);
        }
    }

    public function test_non_numeric_ids_are_dropped(): void
    {
        $request = Request::create('/jobs', 'GET', [
            'activity' => '1abc',
            'structure' => '-5',
            'installer' => '0',
        ]);

        $filter = JobFilter::fromRequest($request);

        $this->assertNull($filter->activity);
        $this->assertNull($filter->structure);
        $this->assertNull($filter->installer);
    }

    public function test_blank_search_becomes_null_and_padding_is_trimmed(): void
    {
        $this->assertNull((new JobFilter(null, null, null, null, null, '   '))->search);
        $this->assertSame('allos', (new JobFilter(null, null, null, null, null, '  allos '))->search);
    }

    public function test_from_request_normalises_the_query_string(): void
    {
        $request = Request::create('/jobs', 'GET', [
            'scope' => 'Corpmates',
            'activity' => '11',
            'status' => 'ready',
            'structure' => '1052778927163',
            'installer' => '1001',
            'q' => ' allos ',
        ]);

        $filter = JobFilter::fromRequest($request);

        $this->assertSame(JobScope::CORPMATES, $filter->scope);
        $this->assertSame(11, $filter->activity);
        $this->assertSame('ready', $filter->status);
        $this->assertSame(1052778927163, $filter->structure);
        $this->assertSame(1001, $filter->installer);
        $this->assertSame('allos', $filter->search);
        $this->assertTrue($filter->hasRestrictions());
    }

    public function test_scope_tab_filters_on_membership(): void
    {
        $filter = new JobFilter(JobScope::CORPMATES);

        $this->assertFalse($filter->matches($this->job()));
        $this->assertTrue($filter->matches($this->job([
            'scopes' => [JobScope::CORPORATION, JobScope::CORPMATES],
        ])));

        // matchesIgnoringScope is what the tab counts use.
        $this->assertTrue($filter->matchesIgnoringScope($this->job()));
    }

    public function test_activity_status_structure_and_installer_each_narrow_the_set(): void
    {
        $this->assertTrue((new JobFilter(null, 1))->matches($this->job()));
        $this->assertFalse((new JobFilter(null, 11))->matches($this->job()));

        $this->assertTrue((new JobFilter(null, null, 'active'))->matches($this->job()));
        $this->assertFalse((new JobFilter(null, null, 'ready'))->matches($this->job()));

        $this->assertTrue((new JobFilter(null, null, null, 1052778927163))->matches($this->job()));
        $this->assertFalse((new JobFilter(null, null, null, 999999999999))->matches($this->job()));

        $this->assertTrue((new JobFilter(null, null, null, null, 1001))->matches($this->job()));
        $this->assertFalse((new JobFilter(null, null, null, null, 7777))->matches($this->job()));
    }

    public function test_search_is_case_insensitive_and_spans_the_named_columns(): void
    {
        foreach (['ALLOS', 'allos', 'raptor werks', '6z-cks', 'star alliance', 'manufacturing', 'wolf vdz'] as $needle) {
            $filter = new JobFilter(null, null, null, null, null, $needle);
            $this->assertTrue($filter->matches($this->job()), $needle);
        }

        $this->assertFalse((new JobFilter(null, null, null, null, null, 'nevergonnamatch'))->matches($this->job()));
    }

    public function test_parameters_preserve_filters_across_scope_tabs(): void
    {
        $filter = new JobFilter(JobScope::PERSONAL, 11, 'ready', null, null, 'allos');

        $this->assertSame(
            ['scope' => 'personal', 'activity' => '11', 'status' => 'ready', 'q' => 'allos'],
            $filter->parameters()
        );

        $this->assertSame(
            ['scope' => 'corpmates', 'activity' => '11', 'status' => 'ready', 'q' => 'allos'],
            $filter->parameters(JobScope::CORPMATES)
        );

        // Clearing the restrictions keeps only the scope.
        $this->assertSame(['scope' => 'personal'], (new JobFilter(JobScope::PERSONAL))->parameters());
    }
}
