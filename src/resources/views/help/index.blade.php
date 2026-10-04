@extends('web::layouts.grids.12')

@section('title', 'Help — Industry Manager')
@section('page_header', 'Help & Documentation')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=3">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-question-circle mr-2"></i> Help &amp; Documentation</h3>
            </div>
            <div class="card-body">
                <h4>Recipe data</h4>
                <p class="im-text-muted">Some tools (the production calculator, schematics, invention, reactions) need EVE's industry &amp; planetary recipes — what each blueprint or schematic consumes, produces, and how long it takes. <strong>This build does not load that recipe data yet</strong>, so those pages show a "recipe data not loaded" notice.</p>
                <p class="im-text-muted">Everything that reads data SeAT already syncs works today: your blueprints, industry jobs, structures, and planetary colonies. Recipe import will return in a later update.</p>

                <h4>What's in v1.0.0</h4>
                <ul class="im-text-muted">
                    <li><strong>Blueprint Library</strong> — your characters' and corporations' blueprints, grouped by type, with originals/copies and best ME/TE.</li>
                    <li><strong>Production Calculator</strong> — pick a blueprint, set ME and runs, and get the exact material list plus a full recursive build tree down to base materials. The "Base Materials" panel is your shopping list.</li>
                    <li><strong>Production Tree</strong> — expand/collapse each buildable sub-component to see the whole manufacturing chain.</li>
                </ul>

                <h4>How material quantities are calculated</h4>
                <p class="im-text-muted">Per material, Industry Manager uses EVE's formula: <code>required = max(runs, ceil(round(baseQuantity &times; runs &times; (1 - ME/100) &times; rigModifier, 2)))</code>. Deeper components in the tree use the "Sub-build ME" you choose (default 0). <code>rigModifier</code> is <code>1 - effective ME bonus / 100</code> for the structure you pick, and 1.0 when no structure is chosen.</p>

                <h4>Structures &amp; rig bonuses</h4>
                <p class="im-text-muted">
                    Bonuses are read from the fitted rig's own dogma attributes — 2593 (TE), 2594 (ME), 2595 (job cost) — and scaled by the
                    security multiplier stored on that rig (2355 high-sec, 2356 low-sec, 2357 null/Wormhole). Only one of the three is ever
                    populated on a given rig. Skills affect duration and eligibility only, never material quantities.
                </p>

                <h4>Coming next</h4>
                <ul class="im-text-muted">
                    <li><strong>Best structure ranking</strong> — needs the rig's activity/size restriction confirmed, so the picker can tell whether a given ME rig applies to a given blueprint's category.</li>
                    <li><strong>Recipe data</strong> — the calculator, invention, reactions and PI tree need EVE's industry recipe data, which is not part of SeAT's core SDE. See Settings for the current source.</li>
                    <li><strong>ISK valuation</strong> — material cost, product value, and build-vs-buy, via Manager Core pricing (v1.1).</li>
                </ul>

                <h4>For plugin administrators</h4>
                <ul class="im-text-muted">
                    <li><strong>No ESI calls</strong> from this plugin. SeAT does the syncing; Industry Manager only reads.</li>
                    <li><strong>Blueprints</strong> come from SeAT's <code>character_blueprints</code> / <code>corporation_blueprints</code>. If a page is empty, SeAT may still be syncing — check SeAT's status page.</li>
                    <li><strong>Diagnostic</strong> lives at <code>/industry-manager/diagnostic</code> (admin-only, not in the sidebar). It hosts the rig attribute-ID discovery tool used to validate structure-bonus math.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@stop
