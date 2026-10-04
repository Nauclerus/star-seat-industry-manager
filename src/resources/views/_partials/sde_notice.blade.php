{{-- Shown when the plugin's recipe tables aren't populated. The live-data
     features (blueprints, jobs, structures, PI colonies) still work. --}}
<div class="im-sde-notice">
    <h4><i class="fas fa-circle-info mr-2"></i> Recipe data not loaded</h4>
    <p>The production calculator, schematics, invention and reactions need EVE's industry recipe data, which SeAT's core SDE does not contain.</p>
    <p class="im-text-muted">
        An administrator can load it with <code>php artisan industry-manager:import-recipes</code>.
        The command uses CCP's official JSONL SDE when SeAT core can provide it, and Fuzzwork's per-table dumps otherwise.
        Everything else works from data SeAT already syncs — your blueprints, industry jobs, structures, and planetary colonies.
    </p>
</div>
