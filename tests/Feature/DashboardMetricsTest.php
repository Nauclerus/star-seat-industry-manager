<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use IndustryManager\Services\JobsService;
use IndustryManager\Tests\TestCase;

/**
 * The dashboard's industry-job metrics: how many jobs are running right now
 * and what this calendar month's installations cost, over the same entitlement
 * set as the jobs list.
 *
 * The sample period is pinned to October 2026 so "the current month" is
 * deterministic. Costs are ESI's reported install cost, the `cost` column SeAT
 * syncs onto character_industry_jobs / corporation_industry_jobs.
 */
class DashboardMetricsTest extends TestCase
{
    private const USER_ID = 1;
    private const CHARACTER_ID = 100;
    private const CORPORATION_ID = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

        $this->createSyncTableStubs();

        View::addNamespace('industry-manager', __DIR__ . '/../../src/resources/views');
        $this->app['translator']->addNamespace('industry-manager', __DIR__ . '/../../src/resources/lang');

        // Stub the SeAT web layout so @extends resolves without the wider app.
        $layoutDir = sys_get_temp_dir() . '/im-layout-' . uniqid();
        @mkdir($layoutDir . '/layouts/grids', 0777, true);
        file_put_contents($layoutDir . '/layouts/grids/12.blade.php', "@yield('full')");
        View::replaceNamespace('web', $layoutDir);

        Route::get('/industry-manager/blueprints', fn () => '')->name('industry-manager.blueprints');
        Route::get('/industry-manager/calculator', fn () => '')->name('industry-manager.calculator');
        Route::get('/industry-manager/structures', fn () => '')->name('industry-manager.structures');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A logged-in user with one linked character in one corporation — the
     * entitlement set CharacterResolver feeds JobsService.
     */
    private function linkUserCharacter(): void
    {
        DB::table('refresh_tokens')->insert([
            'user_id' => self::USER_ID,
            'character_id' => self::CHARACTER_ID,
            'deleted_at' => null,
        ]);
        DB::table('character_affiliations')->insert([
            'character_id' => self::CHARACTER_ID,
            'corporation_id' => self::CORPORATION_ID,
            'alliance_id' => null,
        ]);

        Auth::setUser(new GenericUser(['id' => self::USER_ID]));
    }

    private function characterJob(int $jobId, int $characterId, string $status, float $cost, string $startDate): void
    {
        DB::table('character_industry_jobs')->insert([
            'job_id' => $jobId,
            'character_id' => $characterId,
            'status' => $status,
            'cost' => $cost,
            'start_date' => $startDate,
        ]);
    }

    private function corporationJob(int $jobId, int $corporationId, string $status, float $cost, string $startDate): void
    {
        DB::table('corporation_industry_jobs')->insert([
            'job_id' => $jobId,
            'corporation_id' => $corporationId,
            'status' => $status,
            'cost' => $cost,
            'start_date' => $startDate,
        ]);
    }

    public function test_metrics_match_the_underlying_jobs_for_the_sample_period(): void
    {
        $this->linkUserCharacter();

        // This month (October 2026): two running jobs and one ready job, plus a
        // corporation job. A delivered job from September and one from August
        // must stay out of the month's cost.
        $this->characterJob(1, self::CHARACTER_ID, 'active', 1_000_000, '2026-10-05 08:00:00');
        $this->characterJob(2, self::CHARACTER_ID, 'ready', 250_000, '2026-10-10 09:00:00');
        $this->characterJob(3, self::CHARACTER_ID, 'delivered', 9_000_000, '2026-09-20 09:00:00');
        $this->corporationJob(4, self::CORPORATION_ID, 'active', 5_000_000, '2026-10-12 10:00:00');
        $this->corporationJob(5, self::CORPORATION_ID, 'delivered', 3_000_000, '2026-08-10 10:00:00');

        // A job outside the entitlement set: another character's active job.
        $this->characterJob(6, 999, 'active', 100_000_000, '2026-10-15 10:00:00');

        $metrics = (new JobsService())->metrics();

        // Running = job 1 + job 4. Ready/delivered are not on the line.
        $this->assertSame(2, $metrics['running']);
        // Month cost = 1,000,000 + 250,000 + 5,000,000; job 6 is not ours.
        $this->assertSame(6_250_000.0, $metrics['month_cost']);
        $this->assertSame('October 2026', $metrics['month_label']);
        $this->assertTrue($metrics['has_data']);
    }

    public function test_no_linked_characters_reports_the_empty_metric_set(): void
    {
        // Logged in, but SeAT has linked no characters to this account yet.
        Auth::setUser(new GenericUser(['id' => self::USER_ID]));

        $metrics = (new JobsService())->metrics();

        $this->assertSame(0, $metrics['running']);
        $this->assertSame(0.0, $metrics['month_cost']);
        $this->assertFalse($metrics['has_data']);
    }

    public function test_missing_sync_tables_do_not_break_the_dashboard(): void
    {
        Schema::drop('character_industry_jobs');
        Schema::drop('corporation_industry_jobs');

        Auth::setUser(new GenericUser(['id' => self::USER_ID]));

        $metrics = (new JobsService())->metrics();

        $this->assertSame(0, $metrics['running']);
        $this->assertSame(0.0, $metrics['month_cost']);
        $this->assertFalse($metrics['has_data']);
    }

    public function test_dashboard_view_renders_the_job_metrics(): void
    {
        $html = View::make('industry-manager::index', [
            'stats' => $this->stats(),
            'jobMetrics' => [
                'running' => 2,
                'month_cost' => 6_250_000.0,
                'month_label' => 'October 2026',
                'has_data' => true,
            ],
        ])->render();

        $this->assertStringContainsString('Running Jobs', $html);
        $this->assertStringContainsString('Job Costs · October 2026', $html);
        $this->assertStringContainsString('6,250,000 ISK', $html);
        $this->assertStringNotContainsString('No industry jobs synced yet.', $html);
    }

    public function test_dashboard_view_renders_an_empty_state_without_jobs(): void
    {
        $html = View::make('industry-manager::index', [
            'stats' => $this->stats(),
            'jobMetrics' => [
                'running' => 0,
                'month_cost' => 0.0,
                'month_label' => 'October 2026',
                'has_data' => false,
            ],
        ])->render();

        $this->assertStringContainsString('Running Jobs', $html);
        $this->assertStringContainsString('No industry jobs synced yet.', $html);
    }

    /**
     * The view must still render for callers that pass only $stats (the
     * Structures page regression test does exactly this).
     */
    public function test_dashboard_view_renders_without_a_metrics_variable(): void
    {
        $html = View::make('industry-manager::index', [
            'stats' => $this->stats(),
        ])->render();

        $this->assertStringContainsString('No industry jobs synced yet.', $html);
    }

    private function stats(): array
    {
        return [
            'sde_ready' => true,
            'blueprint_types' => 0,
            'total_blueprints' => 0,
            'bpo_count' => 0,
            'bpc_count' => 0,
        ];
    }

    /**
     * Minimal stubs of the tables SeAT syncs and CharacterResolver reads.
     * The plugin never writes to them.
     */
    private function createSyncTableStubs(): void
    {
        if (! Schema::hasTable('refresh_tokens')) {
            Schema::create('refresh_tokens', function ($table) {
                $table->integer('user_id');
                $table->bigInteger('character_id')->primary();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        if (! Schema::hasTable('character_affiliations')) {
            Schema::create('character_affiliations', function ($table) {
                $table->bigInteger('character_id')->primary();
                $table->bigInteger('corporation_id');
                $table->bigInteger('alliance_id')->nullable();
            });
        }

        if (! Schema::hasTable('character_industry_jobs')) {
            Schema::create('character_industry_jobs', function ($table) {
                $table->bigInteger('character_id');
                $table->bigInteger('job_id');
                $table->string('status');
                $table->double('cost')->nullable();
                $table->dateTime('start_date');
            });
        }

        if (! Schema::hasTable('corporation_industry_jobs')) {
            Schema::create('corporation_industry_jobs', function ($table) {
                $table->bigInteger('corporation_id');
                $table->bigInteger('job_id');
                $table->string('status');
                $table->double('cost')->nullable();
                $table->dateTime('start_date');
            });
        }
    }
}
