<?php

namespace IndustryManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\ProductionCalculator;
use IndustryManager\Tests\TestCase;

/**
 * The Calculator page must compile and render.
 *
 * The structure <option> line once emitted two adjacent Blade directives with
 * no separator (`@endif@if(...)`), so the second @if survived as literal text
 * while its @endif compiled — an orphan endif that made every render throw a
 * PHP syntax error. This guards that regression, the known calculation result,
 * and the form fields/defaults the page must keep.
 */
class CalculatorPageTest extends TestCase
{
    private const BP = 681;
    private const PRODUCT = 165;
    private const MATERIAL = 38;

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('industry-manager', __DIR__ . '/../../src/resources/views');

        // Stub the SeAT web layout so the calculator's @extends resolves
        // without booting the wider SeAT app.
        $layoutDir = sys_get_temp_dir() . '/im-layout-' . uniqid();
        @mkdir($layoutDir . '/layouts/grids', 0777, true);
        file_put_contents($layoutDir . '/layouts/grids/12.blade.php', "@yield('full')");
        View::replaceNamespace('web', $layoutDir);

        // Named routes the view builds links against.
        Route::get('/industry-manager/calculator', fn () => '')->name('industry-manager.calculator');
        Route::get('/industry-manager/blueprints', fn () => '')->name('industry-manager.blueprints');

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

    private function renderPage(): string
    {
        $calc = new ProductionCalculator();
        $tree = $calc->tree(self::BP, [
            'me' => 10,
            'te' => 10,
            'runs' => 2,
            'activity_id' => IndustryActivity::MANUFACTURING,
        ]);
        $recipe = $calc->recipe(self::BP, IndustryActivity::MANUFACTURING);

        return View::make('industry-manager::calculator.index', [
            'sdeReady' => true,
            'bp' => self::BP,
            'me' => 10,
            'te' => 10,
            'runs' => 2,
            'subMe' => 0,
            'subTe' => 0,
            'activity' => IndustryActivity::MANUFACTURING,
            'recipe' => $recipe,
            'tree' => $tree,
            'productName' => 'Test Product',
            'ownedLevels' => [],
            'picker' => collect(),
            // The line that broke: an alliance structure whose contents are
            // unknown, so both conditional clauses on that option are active.
            'structures' => collect([[
                'structure_id' => 102,
                'name' => 'Alliance Keepstar',
                'class' => 'Keepstar',
                'security_class' => 'High Sec',
                'security_multiplier' => 1.0,
                'scope' => 'alliance',
                'contents_known' => false,
            ]]),
            'fit' => null,
        ])->render();
    }

    public function test_page_renders_and_keeps_form_fields_and_defaults(): void
    {
        $html = $this->renderPage();

        // No orphan endif / Blade compile error: the option line renders in full.
        $this->assertStringContainsString('Alliance Keepstar', $html);
        $this->assertStringContainsString('alliance', $html);
        $this->assertStringContainsString('contents unknown', $html);

        // Existing inputs and their submitted defaults survive.
        $this->assertStringContainsString('name="bp"', $html);
        $this->assertMatchesRegularExpression('/name="bp"[^>]*value="681"/', $html);
        $this->assertMatchesRegularExpression('/name="runs"[^>]*value="2"/', $html);
        $this->assertMatchesRegularExpression('/<select name="me"[\s\S]*?<option value="10" selected>/', $html);
        $this->assertMatchesRegularExpression('/<select name="te"[\s\S]*?<option value="10" selected>/', $html);
        $this->assertStringContainsString('name="structure"', $html);
        $this->assertStringContainsString('name="sub_me"', $html);
        $this->assertStringContainsString('name="sub_te"', $html);
    }

    public function test_known_input_set_produces_the_expected_material_quantity(): void
    {
        $html = $this->renderPage();

        // 86 * 2 runs * (1 - 0.10 ME) = 154.8 => 155.
        $this->assertStringContainsString('Test Material', $html);
        $this->assertStringContainsString('155', $html);
    }
}
