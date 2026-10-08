<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Http\Controllers\IndustryManagerController;
use IndustryManager\Tests\TestCase;

/**
 * End-to-end smoke test for the three pages the Calculator polish pass touched.
 *
 * The page tests render the Blade views directly. These drive the real
 * controller actions instead, so route wiring, dependency resolution and the
 * empty-state fallbacks are exercised together:
 *
 *   Calculator — load (empty picker), calculate a known input set, refresh
 *   Structures — load (empty state)
 *   Dashboard  — load and refresh (empty metrics)
 *
 * The signed-out user is deliberate: every page must degrade to its empty state
 * instead of erroring when SeAT has no linked characters or synced data.
 */
class PageSmokeTest extends TestCase
{
    private const BP = 681;
    private const PRODUCT = 165;
    private const MATERIAL = 38;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

        View::addNamespace('industry-manager', __DIR__ . '/../../src/resources/views');
        $this->app['translator']->addNamespace('industry-manager', __DIR__ . '/../../src/resources/lang');

        // Stub the SeAT web layout so @extends resolves without the wider app.
        $layoutDir = sys_get_temp_dir() . '/im-layout-' . uniqid();
        @mkdir($layoutDir . '/layouts/grids', 0777, true);
        file_put_contents($layoutDir . '/layouts/grids/12.blade.php', "@yield('full')@stack('head')");
        View::replaceNamespace('web', $layoutDir);

        Route::get('/industry-manager', fn () => '')->name('industry-manager.index');
        Route::get('/industry-manager/blueprints', fn () => '')->name('industry-manager.blueprints');
        Route::get('/industry-manager/calculator', fn () => '')->name('industry-manager.calculator');
        Route::get('/industry-manager/structures', fn () => '')->name('industry-manager.structures');

        // Blueprint 681 as CCP publishes it: 600 s build time and 86 units of
        // the material. ME 10 / 2 runs => ceil(86 * 2 * 0.9) = 155.
        DB::table(IndustryData::TABLE_ACTIVITY)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::MANUFACTURING,
            'time' => 600,
        ]);
        DB::table(IndustryData::TABLE_PRODUCTS)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::MANUFACTURING,
            'productTypeID' => self::PRODUCT,
            'quantity' => 1,
        ]);
        DB::table(IndustryData::TABLE_MATERIALS)->insert([
            'typeID' => self::BP,
            'activityID' => IndustryActivity::MANUFACTURING,
            'materialTypeID' => self::MATERIAL,
            'quantity' => 86,
        ]);
        DB::table('invTypes')->insert([
            'typeID' => self::BP,
            'typeName' => 'Test Blueprint',
            'groupID' => 708,
        ]);
        DB::table('invTypes')->insert([
            'typeID' => self::PRODUCT,
            'typeName' => 'Test Product',
            'groupID' => 708,
        ]);
        DB::table('invTypes')->insert([
            'typeID' => self::MATERIAL,
            'typeName' => 'Test Material',
            'groupID' => 25,
        ]);

        IndustryData::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Call a controller action — the container resolves its type-hinted services. */
    private function page(string $method, array $params = []): string
    {
        $controller = new IndustryManagerController();

        return (string) $this->app->call([$controller, $method], $params)->render();
    }

    private function calculatorRequest(): Request
    {
        return Request::create(
            '/industry-manager/calculator?bp=' . self::BP . '&me=10&te=10&runs=2',
            'GET'
        );
    }

    public function test_calculator_loads_with_the_empty_picker_state(): void
    {
        $html = $this->page('calculator');

        $this->assertStringContainsString('Production Calculator', $html);
        $this->assertStringContainsString(
            'No blueprints found for your characters or corporations yet.',
            $html
        );
    }

    public function test_calculator_calculates_a_known_input_set(): void
    {
        $html = $this->page('calculator', ['request' => $this->calculatorRequest()]);

        $this->assertStringContainsString('Test Product', $html);
        $this->assertStringContainsString('Test Material', $html);
        // 86 * 2 runs * (1 - 0.10 ME) = 154.8 => 155.
        $this->assertStringContainsString('155', $html);
    }

    public function test_calculator_refreshes_to_the_same_result(): void
    {
        $first = $this->page('calculator', ['request' => $this->calculatorRequest()]);
        $second = $this->page('calculator', ['request' => $this->calculatorRequest()]);

        $this->assertSame($first, $second);
    }

    public function test_structures_loads_with_the_empty_state(): void
    {
        $html = $this->page('structures');

        $this->assertStringContainsString('Structures', $html);
        $this->assertStringContainsString(
            'No industry structures found for your corporations.',
            $html
        );
    }

    public function test_dashboard_loads_with_the_empty_job_metrics(): void
    {
        $html = $this->page('index');

        $this->assertStringContainsString('Running Jobs', $html);
        $this->assertStringContainsString('No industry jobs synced yet.', $html);
    }

    public function test_dashboard_refreshes_to_the_same_result(): void
    {
        $first = $this->page('index');
        $second = $this->page('index');

        $this->assertSame($first, $second);
    }
}
