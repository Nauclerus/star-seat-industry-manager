<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use IndustryManager\Tests\TestCase;

/**
 * The general polish pass.
 *
 * Guards the cross-page cleanup: Calculator, Structures and Dashboard share
 * the same chrome and stylesheet revision, the dashboard tab title follows the
 * plugin's "Page — Industry Manager" pattern, navigation between the three
 * pages resolves to their named routes, and the superseded blueprint-detail
 * placeholder view (the redirect's old target) is gone.
 */
class PluginPolishTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    private function renderDashboard(): string
    {
        return View::make('industry-manager::index', [
            'stats' => [
                'sde_ready' => true,
                'blueprint_types' => 3,
                'total_blueprints' => 4,
                'bpo_count' => 2,
                'bpc_count' => 2,
            ],
            'jobMetrics' => [
                'running' => 1,
                'month_cost' => 1000.0,
                'month_label' => 'October 2026',
                'has_data' => true,
            ],
        ])->render();
    }

    private function renderCalculator(): string
    {
        return View::make('industry-manager::calculator.index', [
            'sdeReady' => true,
            'bp' => null,
            'me' => 0,
            'te' => 0,
            'runs' => 1,
            'subMe' => 0,
            'subTe' => 0,
            'activity' => 1,
            'recipe' => null,
            'tree' => null,
            'productName' => null,
            'ownedLevels' => [],
            'picker' => collect(range(1, 61))->map(fn ($i) => [
                'type_id' => $i,
                'type_name' => "Blueprint $i",
                'best_me' => 0,
                'best_te' => 0,
                'total' => 1,
            ]),
            'structures' => collect(),
            'fit' => null,
        ])->render();
    }

    private function renderStructures(): string
    {
        return View::make('industry-manager::structures.index', [
            'structures' => collect(),
        ])->render();
    }

    /**
     * The shared chrome contract from the design-system CSS: every page wraps
     * its content the same way and loads the same stylesheet revision.
     */
    public function test_calculator_structures_and_dashboard_share_the_same_chrome(): void
    {
        $css = asset('vendor/industry-manager/css/industry-manager.css') . '?v=8';

        foreach ([
            'dashboard' => $this->renderDashboard(),
            'calculator' => $this->renderCalculator(),
            'structures' => $this->renderStructures(),
        ] as $page => $html) {
            $this->assertStringContainsString('class="industry-manager-wrapper"', $html, "$page wrapper");
            $this->assertStringContainsString('class="industry-manager"', $html, "$page inner wrapper");
            $this->assertStringContainsString($css, $html, "$page stylesheet revision");
        }
    }

    /**
     * The dashboard card header uses the same `card-title mb-0` convention as
     * the Calculator and Structures cards.
     */
    public function test_dashboard_card_header_matches_the_plugin_convention(): void
    {
        $this->assertStringContainsString('class="card-title mb-0"', $this->renderDashboard());
    }

    /**
     * Every dashboard quicklink resolves to the named route it advertises.
     */
    public function test_dashboard_quicklinks_resolve_to_their_routes(): void
    {
        $html = $this->renderDashboard();

        $this->assertStringContainsString(route('industry-manager.blueprints'), $html);
        $this->assertStringContainsString(route('industry-manager.calculator'), $html);
        $this->assertStringContainsString(route('industry-manager.structures'), $html);
    }

    /**
     * The calculator form and its cross-link back to the library resolve.
     * The picker is over the 60-item display cap so the overflow note (and its
     * Blueprint Library link) renders.
     */
    public function test_calculator_links_resolve(): void
    {
        $html = $this->renderCalculator();

        $this->assertStringContainsString('action="' . route('industry-manager.calculator') . '"', $html);
        $this->assertStringContainsString(route('industry-manager.blueprints'), $html);
    }

    /**
     * The superseded blueprint-detail placeholder is unreachable: the route
     * redirects to the calculator and no controller returns this view.
     */
    public function test_blueprint_detail_placeholder_view_is_gone(): void
    {
        $this->assertFalse(View::exists('industry-manager::blueprints.detail'));
    }
}
