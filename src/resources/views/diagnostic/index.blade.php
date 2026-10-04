@extends('web::layouts.grids.12')

@section('title', 'Diagnostic — Industry Manager')
@section('page_header', 'Industry Manager Diagnostic')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=6">
<style>
    .im-discovery .step-section { margin-bottom: 1.5rem; padding: 1rem; background-color: rgba(0,0,0,0.15); border: 1px solid var(--im-border); border-radius: var(--im-radius-md); }
    .im-discovery .step-section h4 { color: var(--im-text-white); margin-bottom: 0.5rem; }
    .im-discovery table { width: 100%; margin-bottom: 0; color: var(--im-text-light); }
    .im-discovery table th { background-color: var(--im-dark-card); color: var(--im-text-white); padding: 0.5rem; border-bottom: 1px solid var(--im-border); text-align: left; font-size: 0.85rem; font-weight: 600; }
    .im-discovery table td { padding: 0.4rem 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.85rem; }
    .im-discovery .attr-hint { color: var(--im-warning); font-style: italic; font-size: 0.85rem; }
    .im-discovery .attr-id-pill { display: inline-block; background: linear-gradient(135deg, var(--im-primary-start) 0%, var(--im-primary-end) 100%); color: #fff; padding: 0.15rem 0.5rem; border-radius: var(--im-radius-md); font-weight: 600; font-family: monospace; }
    .im-discovery .value-cluster { font-family: monospace; background-color: rgba(255,255,255,0.05); padding: 0.1rem 0.4rem; border-radius: var(--im-radius-sm); margin-right: 0.25rem; display: inline-block; margin-bottom: 0.2rem; }
    .im-discovery details summary { cursor: pointer; color: var(--im-text-light); padding: 0.25rem 0; }
    .im-discovery .empty-state { color: var(--im-text-muted); font-style: italic; padding: 0.5rem 0; }
    .industry-manager-wrapper .nav-tabs .nav-link { color: #c2c7d0; border: none; border-radius: 8px 8px 0 0; }
    .industry-manager-wrapper .nav-tabs .nav-link.active { background-color: var(--im-dark-card); color: #fff; border: 1px solid var(--im-border); border-bottom: none; }
    .im-trace-table td, .im-trace-table th { font-family: monospace; font-size: 0.82rem; }
</style>
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager im-discovery">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-stethoscope mr-2"></i> Industry Manager — Diagnostic</h3>
            </div>
            <div class="card-body">

                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-health"><i class="fas fa-heart-pulse mr-1"></i> Health Checks</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-integrity"><i class="fas fa-database mr-1"></i> Data Integrity</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-trace"><i class="fas fa-route mr-1"></i> Industry Trace</a></li>
                    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-discovery"><i class="fas fa-magnifying-glass-chart mr-1"></i> Attribute Discovery</a></li>
                </ul>

                <div class="tab-content pt-3">

                    {{-- ============ HEALTH ============ --}}
                    <div class="tab-pane fade show active" id="tab-health">
                        <div class="diag-tab-intro">
                            <h4>What this tab does</h4>
                            <p>Confirms the plugin can see its data: whether the industry SDE tables are loaded, how many rows each has, and the active cache driver.</p>
                            <h4>When to use</h4>
                            <p>First stop if any page is empty or showing the "recipe data not loaded" notice.</p>
                            <h4>Heads up</h4>
                            <p>Row counts are cached for 5 minutes.</p>
                        </div>

                        @if($sde['installed'])
                            <div class="alert im-inline-alert alert-mc-success"><i class="fas fa-circle-check mr-2"></i> Industry recipe data loaded. SDE version <code>{{ $sde['version'] ?? 'unknown' }}</code>.</div>
                        @else
                            <div class="alert im-inline-alert alert-mc-warning"><i class="fas fa-circle-info mr-2"></i> Industry recipe data NOT loaded. SDE import is disabled in this build; recipe-powered pages stay empty, but the live-data pages (blueprints, jobs, structures, planetary colonies) work.</div>
                        @endif

                        <table class="table im-table im-table-compact">
                            <thead><tr><th>SDE table</th><th class="text-center">Present</th><th class="text-right">Rows</th></tr></thead>
                            <tbody>
                                @foreach($sde['tables'] as $name => $info)
                                    <tr>
                                        <td><code>{{ $name }}</code></td>
                                        <td class="text-center">@if($info['exists'])<span class="im-badge im-status-ready">yes</span>@else<span class="im-badge im-status-cancelled">no</span>@endif</td>
                                        <td class="text-right">{{ number_format($info['rows']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="im-text-muted">Cache driver: <code>{{ $cacheDriver }}</code></p>
                    </div>

                    {{-- ============ DATA INTEGRITY ============ --}}
                    <div class="tab-pane fade" id="tab-integrity">
                        <div class="diag-tab-intro">
                            <h4>What this tab does</h4>
                            <p>Sanity-checks recipe coverage: how many blueprints have a manufacturing recipe, how many reaction formulas exist, and the total material rows.</p>
                            <h4>When to use</h4>
                            <p>After an SDE update, to confirm the industry tables imported fully (not a partial/aborted download).</p>
                            <h4>Heads up</h4>
                            <p>Recipe tables are not populated in this build (SDE import is disabled), so zero counts are expected here for now.</p>
                        </div>

                        <div class="im-result-grid">
                            <div class="im-result-stat"><div class="im-result-label">Manufacturing blueprints</div><div class="im-result-value">{{ number_format($coverage['manufacturing_blueprints']) }}</div></div>
                            <div class="im-result-stat"><div class="im-result-label">Reaction formulas</div><div class="im-result-value">{{ number_format($coverage['reaction_formulas']) }}</div></div>
                            <div class="im-result-stat"><div class="im-result-label">Material rows</div><div class="im-result-value">{{ number_format($coverage['material_rows']) }}</div></div>
                        </div>
                    </div>

                    {{-- ============ INDUSTRY TRACE ============ --}}
                    <div class="tab-pane fade" id="tab-trace">
                        <div class="diag-tab-intro">
                            <h4>What this tab does</h4>
                            <p>Walks the material calculation for one blueprint line by line: base quantity → ×runs → ME modifier → rounded → final. This is how you reconcile a number against the in-game industry window.</p>
                            <h4>When to use</h4>
                            <p>When a calculator result disagrees with the game. Find the material whose <code>final</code> differs and the step where it diverges.</p>
                            <h4>Heads up</h4>
                            <p>Leave the structure blank to trace an unrigged fit, which is the case the in-game window is easiest to reconcile against.</p>
                        </div>

                        <form method="GET" action="{{ route('industry-manager.diagnostic') }}" class="im-calc-form form-row align-items-end">
                            <input type="hidden" name="_tab" value="trace">
                            <div class="form-group col-md-4"><label>Blueprint type ID</label><input type="number" name="trace_bp" class="form-control" value="{{ $traceBp }}"></div>
                            <div class="form-group col-md-2"><label>ME</label><input type="number" name="trace_me" class="form-control" value="{{ $traceMe }}" min="0" max="10"></div>
                            <div class="form-group col-md-2"><label>Runs</label><input type="number" name="trace_runs" class="form-control" value="{{ $traceRuns }}" min="1"></div>
                            <div class="form-group col-md-2"><label>Structure ID</label><input type="number" name="trace_structure" class="form-control" value="{{ $traceStructure }}" placeholder="optional"></div>
                            <div class="form-group col-md-2"><button class="btn btn-im-primary btn-block" type="submit">Trace</button></div>
                        </form>

                        @if($traceFit)
                            <p class="im-text-muted">
                                Rig modifier applied from {{ $traceFit['name'] }}:
                                {{ $traceFit['me_bonus'] }}% ME bonus &rarr;
                                <code>×{{ number_format(1 - $traceFit['me_bonus'] / 100, 4) }}</code>
                                ({{ $traceFit['security_class'] }}, ×{{ $traceFit['multiplier'] }}).
                            </p>
                        @endif

                        @if($trace)
                            <p class="im-text-muted">Tracing <strong>{{ $trace['recipe']['blueprint_name'] }}</strong> at ME {{ $trace['me'] }}, {{ $trace['runs'] }} run(s). Modifier = (1 - ME/100) × structure({{ $trace['structure_modifier'] }}) × rig({{ $trace['rig_modifier'] }}).</p>
                            <table class="table im-table im-table-compact im-trace-table">
                                <thead><tr><th>Material</th><th class="text-right">base</th><th class="text-right">×runs</th><th class="text-right">×mod</th><th class="text-right">adjusted</th><th class="text-right">round(2)</th><th class="text-right">final</th></tr></thead>
                                <tbody>
                                    @foreach($trace['rows'] as $r)
                                        <tr>
                                            <td style="font-family: inherit;">{{ $r['name'] }}</td>
                                            <td class="text-right">{{ $r['base_quantity'] }}</td>
                                            <td class="text-right">{{ $r['after_runs'] }}</td>
                                            <td class="text-right">{{ rtrim(rtrim(number_format($r['modifier'], 4), '0'), '.') }}</td>
                                            <td class="text-right">{{ $r['adjusted'] }}</td>
                                            <td class="text-right">{{ $r['rounded'] }}</td>
                                            <td class="text-right"><strong>{{ number_format($r['final']) }}</strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @elseif($traceBp)
                            <div class="alert im-inline-alert alert-mc-warning">No recipe for blueprint <code>{{ $traceBp }}</code>.</div>
                        @endif
                    </div>

                    {{-- ============ ATTRIBUTE DISCOVERY ============ --}}
                    <div class="tab-pane fade" id="tab-discovery">
                        <div class="diag-tab-intro">
                            <h4>What this tab does</h4>
                            <p>Scans the SDE for industry rig dogma attributes so you can identify the ME / TE / cost / security-multiplier attribute IDs — the values that drive structure bonus math.</p>
                            <h4>When to use</h4>
                            <p>Once, to calibrate <code>RigAttributes</code> so the Structures page and calculator can show real rig bonuses.</p>
                            <h4>What to look for</h4>
                            <p>Step 5 (cross-reference): attributes are sorted by how many rigs use them; the hint column flags likely roles. Confirm against an in-game industry window, then report the IDs.</p>
                        </div>

                        @if($error)
                            <div class="alert alert-danger"><strong>Discovery failed:</strong> {{ $error }}<br><small>This reads SeAT's core SDE (invTypes/dgmTypeAttributes). If those are missing, your SeAT core SDE itself needs attention.</small></div>
                        @else
                            <div class="step-section">
                                <h4>Step 1 &mdash; Structure categories ({{ count($categories) }})</h4>
                                @if(empty($categories))<div class="empty-state">None found — SDE not seeded.</div>
                                @else<table><thead><tr><th>categoryID</th><th>categoryName</th></tr></thead><tbody>@foreach($categories as $c)<tr><td><code>{{ $c['categoryID'] }}</code></td><td>{{ $c['categoryName'] }}</td></tr>@endforeach</tbody></table>@endif
                            </div>
                            <div class="step-section">
                                <h4>Step 2 &mdash; Rig groups ({{ count($rigGroups) }})</h4>
                                @if(empty($rigGroups))<div class="empty-state">None found.</div>
                                @else<table><thead><tr><th>groupID</th><th>groupName</th><th>members</th></tr></thead><tbody>@foreach($rigGroups as $g)<tr><td><code>{{ $g['groupID'] }}</code></td><td>{{ $g['groupName'] }}</td><td>{{ $g['member_count'] }}</td></tr>@endforeach</tbody></table>@endif
                            </div>
                            <div class="step-section">
                                <h4>Step 3 &mdash; Industry rig types ({{ count($rigTypes) }})</h4>
                                @if(empty($rigTypes))<div class="empty-state">None found.</div>
                                @else<details><summary>Show {{ count($rigTypes) }} rig types</summary><table><thead><tr><th>typeID</th><th>typeName</th><th>group</th></tr></thead><tbody>@foreach($rigTypes as $t)<tr><td><code>{{ $t['typeID'] }}</code></td><td>{{ $t['typeName'] }}</td><td>{{ $t['groupName'] }}</td></tr>@endforeach</tbody></table></details>@endif
                            </div>
                            <div class="step-section">
                                <h4>Step 5 &mdash; Cross-reference (the payoff)</h4>
                                @if(empty($crossRef))<div class="empty-state">Nothing to cross-reference.</div>
                                @else
                                    <table>
                                        <thead><tr><th>attributeID</th><th>appears_on</th><th>distinct</th><th>sample values</th><th>hint</th></tr></thead>
                                        <tbody>
                                            @foreach($crossRef as $aid => $info)
                                                <tr>
                                                    <td><span class="attr-id-pill">{{ $info['attributeID'] }}</span></td>
                                                    <td>{{ $info['appears_on_count'] }}</td>
                                                    <td>{{ $info['distinct_value_count'] }}</td>
                                                    <td>@foreach($info['sample_values'] as $v)<span class="value-cluster">{{ $v }}</span>@endforeach</td>
                                                    <td>@if($info['hint'])<span class="attr-hint"><i class="fas fa-lightbulb mr-1"></i>{{ $info['hint'] }}</span>@endif</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    <p class="im-text-muted mt-2">Report the ME / TE / cost / security-multiplier attribute IDs and they get wired into <code>RigAttributes</code>.</p>
                                @endif
                            </div>
                        @endif
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>
@stop

@push('javascript')
<script>
    $(function () {
        // Deep-link to the trace tab after a trace submit.
        if (window.location.search.indexOf('trace_bp=') !== -1) {
            $('.nav-tabs a[href="#tab-trace"]').tab('show');
        }
    });
</script>
@endpush
