@extends('web::layouts.grids.12')

@section('title', 'Calculator — Industry Manager')
@section('page_header', 'Production Calculator')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=7">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        @unless($sdeReady)
            @include('industry-manager::_partials.sde_notice')
        @endunless

        {{-- Inputs --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-calculator mr-2"></i> Production Calculator</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('industry-manager.calculator') }}" class="im-calc-form">
                    <div class="form-row align-items-end">
                        <div class="form-group col-md-4">
                            <label>Blueprint type ID</label>
                            <input type="number" name="bp" class="form-control" value="{{ $bp }}" placeholder="e.g. 689" required>
                            <small class="im-text-muted">Pick from your library below, or paste a blueprint type ID.</small>
                        </div>
                        <div class="form-group col-md-2">
                            <label>ME %</label>
                            <select name="me" class="form-control">
                                @for($i = 0; $i <= 10; $i++)
                                    <option value="{{ $i }}" {{ $me === $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>TE %</label>
                            <select name="te" class="form-control">
                                @for($i = 0; $i <= 10; $i++)
                                    <option value="{{ $i }}" {{ $te === $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>Runs</label>
                            <input type="number" name="runs" class="form-control" value="{{ $runs }}" min="1" max="100000">
                        </div>
                        <div class="form-group col-md-2">
                            <button type="submit" class="btn btn-im-primary btn-block">
                                <i class="fas fa-cogs mr-1"></i> Calculate
                            </button>
                        </div>
                    </div>

                    <div class="form-row align-items-end">
                        <div class="form-group col-md-2">
                            <label>Sub-build ME %</label>
                            <select name="sub_me" class="form-control">
                                @for($i = 0; $i <= 10; $i++)
                                    <option value="{{ $i }}" {{ $subMe === $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                            <small class="im-text-muted">Assumed ME for deeper components.</small>
                        </div>
                        <div class="form-group col-md-2">
                            <label>Sub-build TE %</label>
                            <select name="sub_te" class="form-control">
                                @for($i = 0; $i <= 10; $i++)
                                    <option value="{{ $i }}" {{ $subTe === $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                            <small class="im-text-muted">Assumed TE for deeper components.</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Structure (applies its services and rig bonuses)</label>
                            <select name="structure" class="form-control">
                                <option value="">Choose per run</option>
                                @foreach($structures as $st)
                                    <option value="{{ $st['structure_id'] }}" {{ (int) request('structure') === $st['structure_id'] ? 'selected' : '' }}>
                                        {{ $st['name'] }} — {{ $st['class'] }} · {{ $st['security_class'] }} (×{{ $st['security_multiplier'] }})@if($st['scope'] === 'alliance') · alliance @endif@if(!($st['contents_known'] ?? true)) · contents unknown @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if(!empty($ownedLevels))
                        <div class="im-owned-me">
                            <span class="im-text-muted">You own this blueprint at:</span>
                            @foreach($ownedLevels as $own)
                                <a href="{{ route('industry-manager.calculator', ['bp' => $bp, 'me' => $own['me'], 'te' => $own['te'], 'runs' => $runs, 'sub_me' => $subMe, 'sub_te' => $subTe]) }}"
                                   class="im-pill {{ $me === $own['me'] && $te === $own['te'] ? 'active' : '' }}">ME {{ $own['me'] }} · TE {{ $own['te'] }}</a>
                            @endforeach
                        </div>
                    @endif
                </form>
            </div>
        </div>

        @if($fit)
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title mb-0"><i class="fas fa-building mr-2"></i> Applied fit: {{ $fit['name'] }}</h3>
                </div>
                <div class="card-body">
                    <p class="im-text-muted">
                        {{ $fit['type_name'] }} in {{ $fit['system_name'] }} —
                        {{ $fit['security_class'] }}{{ $fit['security'] !== null ? ' (' . number_format($fit['security'], 1) . ')' : '' }},
                        multiplier ×{{ $fit['multiplier'] }}.
                    </p>

                    @if(empty($fit['me_bonus']))
                        <p class="im-text-muted">No material-efficiency rig fitted.</p>
                    @else
                        <p>
                            Material modifier applied:
                            <code>×{{ number_format(1 - $fit['me_bonus'] / 100, 4) }}</code>
                            ({{ $fit['me_bonus'] }}% ME bonus).
                        </p>
                    @endif

                    @if($fit['te_bonus'] > 0 || $fit['cost_bonus'] > 0)
                        <p class="im-text-muted">
                            @if($fit['te_bonus'] > 0)TE rig {{ $fit['te_bonus'] }}% — affects duration, not quantities.@endif
                            @if($fit['cost_bonus'] > 0) {{ $fit['cost_bonus'] }}% job cost bonus.@endif
                        </p>
                    @endif
                </div>
            </div>
        @endif

        @if($sdeReady && $bp && !$recipe)
            <div class="alert alert-warning">
                No manufacturing recipe found for blueprint type ID <code>{{ $bp }}</code>. Make sure it's a blueprint (not the product), and that the SDE industry tables are loaded.
            </div>
        @endif

        @if($tree && $recipe)
            @php $totalOutput = ($recipe['product_quantity'] ?? 1) * $runs; @endphp

            {{-- Result header --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-box-open mr-2"></i>
                        {{ $productName ?? $recipe['blueprint_name'] }}
                    </h3>
                </div>
                <div class="card-body">
                    <div class="im-result-grid">
                        <div class="im-result-stat">
                            <div class="im-result-label">Blueprint</div>
                            <div class="im-result-value">{{ $recipe['blueprint_name'] }}</div>
                        </div>
                        <div class="im-result-stat">
                            <div class="im-result-label">Output</div>
                            <div class="im-result-value">{{ number_format($totalOutput) }} <span class="im-text-muted">({{ $runs }} run&times;{{ $recipe['product_quantity'] ?? 1 }})</span></div>
                        </div>
                        <div class="im-result-stat">
                            <div class="im-result-label">Blueprint levels</div>
                            <div class="im-result-value">ME {{ $me }}% · TE {{ $te }}%</div>
                        </div>
                        <div class="im-result-stat">
                            <div class="im-result-label">Time / run</div>
                            <div class="im-result-value">{{ \IndustryManager\Helpers\Format::duration($tree['root']['adjusted_time']) }}</div>
                            <div class="im-result-sub im-text-muted">
                                base {{ \IndustryManager\Helpers\Format::duration($recipe['time'] ?? 0) }}
                                @if($te > 0) · TE -{{ $te * 2 }}% @endif
                            </div>
                        </div>
                    </div>

                    @if(!empty($recipe['skills']))
                        <div class="im-skill-reqs">
                            <span class="im-text-muted mr-2"><i class="fas fa-graduation-cap mr-1"></i>Skills:</span>
                            @foreach($recipe['skills'] as $sk)
                                <span class="im-badge im-badge-skill">{{ $sk['name'] }} {{ $sk['level'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="row">
                {{-- Base materials roll-up (the shopping list) --}}
                <div class="col-lg-5">
                    <div class="card card-dark">
                        <div class="card-header">
                            <h4 class="card-title mb-0"><i class="fas fa-basket-shopping mr-2"></i> Base Materials</h4>
                        </div>
                        <div class="card-body">
                            <p class="im-text-muted">Everything you need to acquire if you build all sub-components down the tree. (Buildable items beyond the depth limit are listed as-is.)</p>
                            <table class="table im-table im-table-compact">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th class="text-right">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($tree['base_materials'] as $bm)
                                        <tr>
                                            <td>{{ $bm['name'] }}</td>
                                            <td class="text-right">{{ number_format($bm['quantity']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="im-text-muted im-no-isk-note"><i class="fas fa-circle-info mr-1"></i> ISK valuation arrives in v1.1 with Manager Core pricing.</p>
                        </div>
                    </div>
                </div>

                {{-- Production tree --}}
                <div class="col-lg-7">
                    <div class="card card-dark">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-sitemap mr-2"></i> Production Tree</h4>
                            <div class="im-tree-controls">
                                <button type="button" class="im-mini-btn" onclick="imTreeToggle(true)"><i class="fas fa-angles-down"></i> Expand</button>
                                <button type="button" class="im-mini-btn" onclick="imTreeToggle(false)"><i class="fas fa-angles-up"></i> Collapse</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="im-tree" id="im-tree">
                                {{-- Root product header row --}}
                                <div class="im-tree-row im-tree-root" style="--im-depth: 0;">
                                    <i class="fas fa-industry im-tree-bullet"></i>
                                    <span class="im-tree-name">{{ $productName ?? $recipe['blueprint_name'] }}</span>
                                    <span class="im-tree-qty">&times;{{ number_format($totalOutput) }}</span>
                                </div>
                                <div class="im-tree-children">
                                    @include('industry-manager::calculator._assignment', ['node' => $tree['root'], 'depth' => 0])
                                    @include('industry-manager::calculator._tree_node', ['node' => $tree['root'], 'depth' => 1])
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        @elseif(!$bp)
            {{-- Picker / empty state --}}
            <div class="card card-dark">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-hand-pointer mr-2"></i> Pick a blueprint</h4>
                </div>
                <div class="card-body">
                    @if($picker->isEmpty())
                        <p class="im-text-muted">No blueprints found for your characters or corporations yet. You can still paste a blueprint type ID above.</p>
                    @else
                        <p class="im-text-muted">Choose one of your blueprints to break down its production:</p>
                        <div class="im-picker-grid">
                            @foreach($picker->take(60) as $p)
                                <a href="{{ route('industry-manager.calculator', ['bp' => $p['type_id'], 'me' => $p['best_me'], 'te' => $p['best_te']]) }}" class="im-picker-item">
                                    <span class="im-picker-name">{{ $p['type_name'] }}</span>
                                    <span class="im-picker-meta">ME {{ $p['best_me'] }} · TE {{ $p['best_te'] }} · {{ $p['total'] }} owned</span>
                                </a>
                            @endforeach
                        </div>
                        @if($picker->count() > 60)
                            <p class="im-text-muted mt-2">Showing first 60 of {{ number_format($picker->count()) }} types. Use the <a href="{{ route('industry-manager.blueprints') }}">Blueprint Library</a> to find a specific one.</p>
                        @endif
                    @endif
                </div>
            </div>
        @endif

    </div>
</div>
@stop

@push('javascript')
<script>
    function imTreeToggle(open) {
        document.querySelectorAll('#im-tree details.im-tree-branch').forEach(function (d) {
            d.open = open;
        });
    }
</script>
@endpush
