<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Services\StructureAccess;
use IndustryManager\Tests\TestCase;

/**
 * The Structures page must compile and render.
 *
 * Guards the cleanup in Task 3: the page still renders every section it owns
 * (services, activities, structure bonus, fitted rigs, scope/access badges)
 * after the superseded dashboard copy was replaced and the dead structure
 * fields (access_denied, security_band, source) were dropped from
 * StructureService::forUser(). The dashboard quicklink to the page must no
 * longer advertise it as "coming soon".
 */
class StructuresPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * A structure as StructureService::forUser() hands it to the view.
     */
    private function structure(array $overrides = []): array
    {
        return array_merge([
            'structure_id' => 102,
            'name' => 'Alliance Tatara',
            'type_name' => 'Tatara',
            'class' => 'Refinery',
            'size' => 'L',
            'system_name' => 'Jita',
            'security' => 0.9,
            'security_class' => 'High-sec',
            'security_multiplier' => 1.0,
            'scope' => 'alliance',
            'access' => StructureAccess::DENIED,
            'contents_known' => true,
            'services' => [
                ['name' => 'Standup Manufacturing Plant I', 'type_id' => 35878, 'quantity' => 1],
            ],
            'activities' => [IndustryActivity::MANUFACTURING => ['activityID' => IndustryActivity::MANUFACTURING]],
            'blocked' => [],
            'bonuses' => [IndustryActivity::MANUFACTURING => ['material' => 0.99, 'cost' => 1.0, 'time' => 1.0]],
            'rigs' => [
                ['name' => 'Standup M-Set ME Rig I', 'bonus' => 'me', 'effective' => 1.0],
            ],
            'me_bonus' => 1.0,
            'te_bonus' => 0,
            'cost_bonus' => 0,
        ], $overrides);
    }

    public function test_page_renders_every_structure_section(): void
    {
        $html = View::make('industry-manager::structures.index', [
            'structures' => collect([$this->structure()]),
        ])->render();

        $this->assertStringContainsString('Alliance Tatara', $html);
        $this->assertStringContainsString('Refinery', $html);
        $this->assertStringContainsString('Jita', $html);
        $this->assertStringContainsString('im-sec-high-sec', $html);
        $this->assertStringContainsString('Alliance', $html);
        $this->assertStringContainsString('No docking', $html);

        $this->assertStringContainsString('Standup Manufacturing Plant I', $html);
        $this->assertStringContainsString('Manufacturing', $html);
        $this->assertStringContainsString('Structure bonus', $html);
        $this->assertStringContainsString('Fitted rigs (1)', $html);
        $this->assertStringContainsString('Standup M-Set ME Rig I', $html);
        $this->assertStringContainsString('Effective for this fit', $html);
    }

    public function test_unknown_contents_structure_reports_the_gap(): void
    {
        $html = View::make('industry-manager::structures.index', [
            'structures' => collect([$this->structure([
                'name' => 'Unread Keepstar',
                'scope' => 'own',
                'access' => StructureAccess::UNKNOWN,
                'contents_known' => false,
                'services' => [],
                'activities' => [],
                'bonuses' => [],
                'rigs' => [],
                'me_bonus' => 0,
                'te_bonus' => 0,
                'cost_bonus' => 0,
            ])]),
        ])->render();

        $this->assertStringContainsString('Unread Keepstar', $html);
        $this->assertStringContainsString('Asset data does not reach inside this structure.', $html);
        $this->assertStringContainsString('Activities unknown', $html);
        $this->assertStringContainsString('No rigs fitted.', $html);
    }

    public function test_empty_state_renders(): void
    {
        $html = View::make('industry-manager::structures.index', [
            'structures' => collect(),
        ])->render();

        $this->assertStringContainsString('No industry structures found for your corporations.', $html);
    }

    public function test_dashboard_structures_link_is_not_advertised_as_coming_soon(): void
    {
        $html = View::make('industry-manager::index', [
            'stats' => [
                'sde_ready' => true,
                'blueprint_types' => 0,
                'total_blueprints' => 0,
                'bpo_count' => 0,
                'bpc_count' => 0,
            ],
        ])->render();

        $this->assertStringContainsString('Structures', $html);
        $this->assertStringNotContainsString('coming soon', $html);
    }
}
