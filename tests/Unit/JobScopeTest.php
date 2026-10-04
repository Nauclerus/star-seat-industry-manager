<?php

namespace IndustryManager\Tests\Unit;

use IndustryManager\Helpers\JobScope;
use PHPUnit\Framework\TestCase;

/**
 * Scope membership: which groups a job belongs to, given who the user's
 * characters and corporations are.
 */
class JobScopeTest extends TestCase
{
    private function context(): array
    {
        return [
            'character_ids' => [1001, 1002],
            'corporation_ids' => [2001],
        ];
    }

    public function test_unknown_scope_tokens_fall_back_to_the_default(): void
    {
        $this->assertSame(JobScope::ALL, JobScope::normalize(null));
        $this->assertSame(JobScope::ALL, JobScope::normalize('nonsense'));
        $this->assertSame(JobScope::ALL, JobScope::normalize(''));

        // Alliance was considered and dropped; it must not come back as a
        // accepted token, otherwise old bookmarks would silently widen the view.
        $this->assertSame(JobScope::ALL, JobScope::normalize('alliance'));

        $this->assertSame(JobScope::PERSONAL, JobScope::normalize('PERSONAL'));
        $this->assertSame(JobScope::CORPMATES, JobScope::normalize('  corpmates  '));
    }

    public function test_all_is_a_filter_and_not_a_membership(): void
    {
        $this->assertContains(JobScope::ALL, JobScope::all());
        $this->assertNotContains(JobScope::ALL, JobScope::memberships());
    }

    public function test_no_scope_reaches_past_the_users_own_corporations(): void
    {
        $this->assertNotContains('alliance', JobScope::all());
        $this->assertNotContains('alliance', JobScope::memberships());
    }

    public function test_character_jobs_are_personal_only(): void
    {
        $this->assertSame(
            [JobScope::PERSONAL],
            JobScope::scopesFor(JobScope::SOURCE_CHARACTER, 1001, 1001, $this->context())
        );

        // A character job for a character outside the user's scope is not
        // visible at all, so it gets no membership.
        $this->assertSame(
            [],
            JobScope::scopesFor(JobScope::SOURCE_CHARACTER, 9999, 9999, $this->context())
        );
    }

    public function test_my_corporation_job_i_installed_is_personal_and_corporate(): void
    {
        $scopes = JobScope::scopesFor(JobScope::SOURCE_CORPORATION, 2001, 1001, $this->context());

        $this->assertSame([JobScope::PERSONAL, JobScope::CORPORATION], $scopes);
        $this->assertNotContains(JobScope::CORPMATES, $scopes);
    }

    public function test_my_corporation_job_someone_else_installed_is_corpmates(): void
    {
        $scopes = JobScope::scopesFor(JobScope::SOURCE_CORPORATION, 2001, 7777, $this->context());

        $this->assertSame([JobScope::CORPORATION, JobScope::CORPMATES], $scopes);
        $this->assertNotContains(JobScope::PERSONAL, $scopes);
    }

    public function test_jobs_from_any_other_corporation_get_nothing(): void
    {
        // Even when one of my characters installed it: the corporation is not
        // mine, so the row does not belong on this board.
        $this->assertSame([], JobScope::scopesFor(JobScope::SOURCE_CORPORATION, 2002, 1001, $this->context()));
        $this->assertSame([], JobScope::scopesFor(JobScope::SOURCE_CORPORATION, 5555, 8888, $this->context()));
    }

    public function test_corpmates_never_apart_from_corporation(): void
    {
        // corpmates is a narrowing of corporation, never a wider group.
        foreach ([2001, 2002, 5555] as $owner) {
            foreach ([1001, 1002, 8888] as $installer) {
                $scopes = JobScope::scopesFor(JobScope::SOURCE_CORPORATION, $owner, $installer, $this->context());

                if (in_array(JobScope::CORPMATES, $scopes, true)) {
                    $this->assertContains(JobScope::CORPORATION, $scopes);
                }
            }
        }
    }

    public function test_matches(): void
    {
        $scopes = [JobScope::PERSONAL, JobScope::CORPORATION];

        $this->assertTrue(JobScope::matches($scopes, JobScope::ALL));
        $this->assertTrue(JobScope::matches($scopes, JobScope::PERSONAL));
        $this->assertTrue(JobScope::matches($scopes, JobScope::CORPORATION));
        $this->assertFalse(JobScope::matches($scopes, JobScope::CORPMATES));

        // A row with no membership is outside the user's entitlement, so even
        // the `all` tab must not show it.
        $this->assertFalse(JobScope::matches([], JobScope::ALL));
    }

    public function test_every_scope_has_a_label_icon_and_description(): void
    {
        foreach (JobScope::all() as $scope) {
            $this->assertNotSame('', JobScope::label($scope), $scope);
            $this->assertMatchesRegularExpression('/^fas fa-/', JobScope::icon($scope), $scope);
            $this->assertNotSame('', JobScope::description($scope), $scope);
        }
    }
}
