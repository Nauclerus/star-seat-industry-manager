<?php

namespace IndustryManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use IndustryManager\Helpers\AttributeDiscovery;
use IndustryManager\Helpers\Decryptor;
use IndustryManager\Helpers\IndustryActivity;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Helpers\JobFilter;
use IndustryManager\Services\BlueprintRepository;
use IndustryManager\Services\CharacterResolver;
use IndustryManager\Services\InventionCalculator;
use IndustryManager\Services\JobsService;
use IndustryManager\Services\ProductionCalculator;
use IndustryManager\Services\ReactionService;
use IndustryManager\Services\RunAssigner;
use IndustryManager\Services\StatusService;
use IndustryManager\Services\StructureIndustryRigs;
use IndustryManager\Services\StructureService;

/**
 * Industry Manager — primary controller.
 *
 * Sprint status:
 *   Sprint 0  -> diagnostic() attribute-ID discovery tool                (SHIPPED)
 *   v1.0.0    -> index/blueprints/calculator on the real engine          (THIS)
 *   later     -> structures rig bonuses, invention, reactions
 */
class IndustryManagerController extends Controller
{
    /**
     * Dashboard — at-a-glance counts over the user's own blueprints, plus the
     * SDE-installed status banner.
     */
    public function index(BlueprintRepository $blueprints)
    {
        $sdeReady = IndustryData::isInstalled();

        $stats = [
            'sde_ready' => $sdeReady,
            'blueprint_types' => 0,
            'total_blueprints' => 0,
            'bpo_count' => 0,
            'bpc_count' => 0,
        ];

        try {
            $rows = $blueprints->forUser();
            $stats['total_blueprints'] = $rows->count();
            $stats['blueprint_types'] = $rows->pluck('type_id')->unique()->count();
            $stats['bpo_count'] = $rows->where('is_bpo', true)->count();
            $stats['bpc_count'] = $rows->where('is_bpo', false)->count();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Industry Manager] dashboard stats failed: ' . $e->getMessage());
        }

        return view('industry-manager::index', compact('stats'));
    }

    /**
     * Blueprint library — the user's own character + corporation blueprints,
     * grouped by type for a compact view. Each row links to the calculator.
     */
    public function blueprints(Request $request, BlueprintRepository $blueprints)
    {
        $sdeReady = IndustryData::isInstalled();

        $ownerType = $request->query('owner'); // 'character' | 'corporation' | null
        $grouped = collect();
        $flat = collect();

        try {
            $flat = $blueprints->forUser(array_filter([
                'owner_type' => in_array($ownerType, ['character', 'corporation'], true) ? $ownerType : null,
            ]));
            $grouped = $blueprints->groupByType($flat);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Industry Manager] blueprint list failed: ' . $e->getMessage());
        }

        return view('industry-manager::blueprints.index', [
            'sdeReady' => $sdeReady,
            'groups' => $grouped,
            'ownerFilter' => $ownerType,
            'totalBlueprints' => $flat->count(),
        ]);
    }

    /**
     * Production calculator + tree visualizer.
     *
     * Query params: bp (blueprint type id), me (0-10), runs, sub_me, activity,
     * structure (a structure id whose fitted rigs are applied).
     * Without bp, shows the picker (the user's blueprints) and an empty state.
     */
    public function calculator(Request $request, BlueprintRepository $blueprints, ProductionCalculator $calc, StructureService $structures)
    {
        $sdeReady = IndustryData::isInstalled();

        $bp = $request->query('bp');
        $me = (int) $request->query('me', 0);
        $te = (int) $request->query('te', 0);
        $runs = (int) $request->query('runs', 1);
        $subMe = (int) $request->query('sub_me', 0);
        $subTe = (int) $request->query('sub_te', 0);
        $activity = (int) $request->query('activity', IndustryActivity::MANUFACTURING);

        // Clamp inputs to sane ranges.
        $me = max(0, min(10, $me));
        $te = max(0, min(10, $te));
        $subMe = max(0, min(10, $subMe));
        $subTe = max(0, min(10, $subTe));
        $runs = max(1, min(100000, $runs));

        // A chosen structure must belong to a corporation this user may use.
        $structureId = ctype_digit((string) $request->query('structure')) ? (int) $request->query('structure') : null;
        $candidates = $structures->forUser();
        $fit = $structureId ? $structures->fitForStructure($structureId) : null;

        // With a structure chosen, every run uses it. Without one, the assigner
        // picks the best structure per run — which is the point, since a rig only
        // covers the scopes its dogma effects write, so the right structure differs
        // between a component blueprint and a ship one.
        $assigner = new RunAssigner($fit ? collect([$fit]) : $candidates);

        $tree = null;
        $recipe = null;
        $productName = null;
        $ownedLevels = [];

        if ($sdeReady && $bp !== null && ctype_digit((string) $bp)) {
            $bp = (int) $bp;
            $recipe = $calc->recipe($bp, $activity);

            if ($recipe) {
                $tree = $calc->tree($bp, [
                    'me' => $me,
                    'te' => $te,
                    'runs' => $runs,
                    'sub_component_me' => $subMe,
                    'sub_component_te' => $subTe,
                    'activity_id' => $activity,
                    'rig_modifier' => $fit ? StructureIndustryRigs::materialModifier($fit) : 1.0,
                    'assigner' => $assigner,
                ]);

                if (! empty($recipe['product_type_id'])) {
                    $productName = DB::table('invTypes')->where('typeID', $recipe['product_type_id'])->value('typeName');
                }

                // Offer the ME/TE pairs the user actually owns for this blueprint,
                // so they can match the calc to a real copy or original.
                $ownedLevels = $blueprints->forUser()
                    ->where('type_id', $bp)
                    ->map(fn ($b) => ['me' => (int) $b['me'], 'te' => (int) $b['te']])
                    ->unique()
                    ->sortByDesc(fn ($l) => $l['me'] * 100 + $l['te'])
                    ->values()
                    ->all();
            }
        }

        // Picker list (the user's blueprint types) for the empty/selection state.
        $picker = collect();
        try {
            $picker = $blueprints->groupByType($blueprints->forUser());
        } catch (\Throwable $e) {
            // non-fatal
        }

        return view('industry-manager::calculator.index', [
            'sdeReady' => $sdeReady,
            'bp' => $bp,
            'me' => $me,
            'te' => $te,
            'runs' => $runs,
            'subMe' => $subMe,
            'subTe' => $subTe,
            'activity' => $activity,
            'recipe' => $recipe,
            'tree' => $tree,
            'productName' => $productName,
            'ownedLevels' => $ownedLevels,
            'picker' => $picker,
            'structures' => $candidates,
            'fit' => $fit,
        ]);
    }

    /**
     * Legacy blueprint-detail route now folds into the calculator, which is the
     * richer view of "what does this blueprint need". Kept as a redirect so any
     * existing links / bookmarks to /blueprint/{id} still resolve.
     */
    public function blueprintDetail($type_id)
    {
        return redirect()->route('industry-manager.calculator', ['bp' => (int) $type_id]);
    }

    public function structures(StructureService $structures)
    {
        return view('industry-manager::structures.index', [
            'structures' => $structures->forUser(),
        ]);
    }

    public function jobs(Request $request, JobsService $jobs)
    {
        $filter = JobFilter::fromRequest($request);
        $data = $jobs->forUser($filter);

        return view('industry-manager::jobs.index', [
            'jobs' => $data['jobs'],
            'counts' => $data['counts'],
            'scopeCounts' => $data['scope_counts'],
            'facets' => $data['facets'],
            'filter' => $filter,
        ]);
    }

    public function invention(Request $request, BlueprintRepository $blueprints, InventionCalculator $inv)
    {
        $sdeReady = IndustryData::isInstalled();
        $bp = $request->query('bp');
        $data = null;

        if ($sdeReady && $bp !== null && ctype_digit((string) $bp)) {
            $data = $inv->invent((int) $bp);
        }

        $picker = collect();
        try {
            $picker = $blueprints->groupByType($blueprints->forUser());
        } catch (\Throwable $e) {
            // non-fatal
        }

        return view('industry-manager::invention.index', [
            'sdeReady' => $sdeReady,
            'bp' => $bp,
            'data' => $data,
            'picker' => $picker,
            'decryptors' => Decryptor::LIST,
            'skillMultV' => Decryptor::SKILL_MULTIPLIER_AT_V,
            'baseMe' => Decryptor::BASE_ME,
            'baseTe' => Decryptor::BASE_TE,
        ]);
    }

    public function reactions(Request $request, ReactionService $reactions, ProductionCalculator $calc)
    {
        $sdeReady = IndustryData::isInstalled();
        $formula = $request->query('formula');
        $recipe = null;
        $productName = null;

        if ($sdeReady && $formula !== null && ctype_digit((string) $formula)) {
            $recipe = $calc->recipe((int) $formula, IndustryActivity::REACTIONS);
            if ($recipe && ! empty($recipe['product_type_id'])) {
                $productName = DB::table('invTypes')->where('typeID', $recipe['product_type_id'])->value('typeName');
            }
        }

        $formulas = collect();
        try {
            $formulas = $reactions->formulas();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Industry Manager] reaction formulas failed: ' . $e->getMessage());
        }

        return view('industry-manager::reactions.index', [
            'sdeReady' => $sdeReady,
            'formula' => $formula,
            'recipe' => $recipe,
            'productName' => $productName,
            'formulas' => $formulas,
        ]);
    }

    public function settings(StatusService $status)
    {
        return view('industry-manager::settings.index', [
            'sde' => $status->sdeStatus(),
            'coverage' => $status->recipeCoverage(),
            'cacheDriver' => $status->cacheDriver(),
        ]);
    }

    public function help()
    {
        return view('industry-manager::help.index');
    }

    /**
     * Diagnostic page — admin-only, tabbed:
     *   Health Checks (default) / Data Integrity / Industry Trace / Attribute Discovery
     */
    public function diagnostic(Request $request, StatusService $status, ProductionCalculator $calc, StructureService $structures)
    {
        // --- Health / Data Integrity ---
        $sde = $status->sdeStatus();
        $coverage = $status->recipeCoverage();
        $cacheDriver = $status->cacheDriver();

        // --- Industry Trace ---
        $traceBp = $request->query('trace_bp');
        $traceMe = max(0, min(10, (int) $request->query('trace_me', 0)));
        $traceRuns = max(1, (int) $request->query('trace_runs', 1));
        $traceStructure = ctype_digit((string) $request->query('trace_structure'))
            ? (int) $request->query('trace_structure')
            : null;
        $traceFit = $traceStructure ? $structures->fitForStructure($traceStructure) : null;
        $trace = null;

        if (IndustryData::isInstalled() && $traceBp !== null && ctype_digit((string) $traceBp)) {
            $trace = $calc->trace(
                (int) $traceBp,
                $traceMe,
                $traceRuns,
                IndustryActivity::MANUFACTURING,
                1.0,
                $traceFit ? StructureIndustryRigs::materialModifier($traceFit) : 1.0
            );
        }

        // --- Attribute Discovery (Sprint 0 tool) ---
        $error = null;
        $categories = [];
        $rigGroups = [];
        $rigTypes = [];
        $attrDump = [];
        $crossRef = [];

        try {
            $categories = AttributeDiscovery::findStructureCategories();
            $rigGroups = AttributeDiscovery::findRigGroups();
            $rigTypes = AttributeDiscovery::findIndustryRigTypes();

            $typeIDs = array_column($rigTypes, 'typeID');
            $attrDump = AttributeDiscovery::dumpAttributesForTypes($typeIDs);
            $crossRef = AttributeDiscovery::crossReferenceAttributes($rigTypes, $attrDump);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            \Illuminate\Support\Facades\Log::warning(
                '[Industry Manager] Diagnostic discovery query failed: ' . $e->getMessage()
            );
        }

        return view('industry-manager::diagnostic.index', [
            'sde' => $sde,
            'coverage' => $coverage,
            'cacheDriver' => $cacheDriver,
            'trace' => $trace,
            'traceBp' => $traceBp,
            'traceMe' => $traceMe,
            'traceRuns' => $traceRuns,
            'traceStructure' => $traceStructure,
            'traceFit' => $traceFit,
            'error' => $error,
            'categories' => $categories,
            'rigGroups' => $rigGroups,
            'rigTypes' => $rigTypes,
            'attrDump' => $attrDump,
            'crossRef' => $crossRef,
        ]);
    }
}
