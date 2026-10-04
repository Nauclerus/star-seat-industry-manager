<?php

namespace IndustryManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use IndustryManager\Helpers\IndustryData;
use IndustryManager\Services\PiSchematicService;
use IndustryManager\Services\PlanetaryService;

/**
 * PlanetaryController — Planetary Industry pages.
 *
 * Pure read-only consumer of SeAT's synced character_planet_* tables (live
 * colony/extractor/factory data) plus the registered PI schematic SDE
 * (factory recipes). No ESI. Inspired by hermesdj/seat-planetary-industry but
 * reimplemented from the public data sources to keep Industry Manager uniformly
 * GPL-2.0-or-later.
 */
class PlanetaryController extends Controller
{
    /**
     * PI Overview — colonies, extractors (with expiry), factories, storage,
     * plus an "attention needed" panel of soon-to-expire extractors.
     */
    public function overview(PlanetaryService $pi)
    {
        // The live colony tables are SeAT-core and always present; only the
        // schematic NAMES/recipes need the PI SDE. So the page renders either
        // way; piReady just controls the schematic-name enrichment + notice.
        $piReady = IndustryData::isPiInstalled();

        return view('industry-manager::pi.overview', [
            'piReady' => $piReady,
            'colonies' => $pi->colonies(),
            'extractors' => $pi->extractors(),
            'factories' => $pi->factories(),
            'storage' => $pi->storage(),
            'expiring' => $pi->expiringExtractors(24),
        ]);
    }

    /**
     * PI Schematics — searchable schematic catalogue + recursive P4->P0 input
     * tree for a chosen output.
     */
    public function schematics(Request $request, PiSchematicService $svc)
    {
        $piReady = IndustryData::isPiInstalled();

        $out = $request->query('out');
        $qty = (int) $request->query('qty', 0);
        $tree = null;
        $recipe = null;

        if ($piReady && $out !== null && ctype_digit((string) $out)) {
            $out = (int) $out;
            $recipe = $svc->recipeForOutput($out);
            if ($recipe) {
                $tree = $svc->tree($out, $qty > 0 ? $qty : null);
            }
        }

        return view('industry-manager::pi.schematics', [
            'piReady' => $piReady,
            'out' => $out,
            'qty' => $qty,
            'recipe' => $recipe,
            'tree' => $tree,
            'schematics' => $piReady ? $svc->allSchematics() : collect(),
        ]);
    }
}
