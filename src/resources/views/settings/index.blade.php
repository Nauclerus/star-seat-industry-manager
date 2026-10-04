@extends('web::layouts.grids.12')

@section('title', 'Settings — Industry Manager')
@section('page_header', 'Settings')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=6">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        {{-- SDE status --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-database mr-2"></i> Industry Data Status</h3>
            </div>
            <div class="card-body">
                @if($sde['installed'])
                    <div class="alert alert-mc-success im-inline-alert"><i class="fas fa-circle-check mr-2"></i> Industry recipe data is loaded. Recipe version: <code>{{ $sde['version'] ?? 'unknown' }}</code>.</div>
                @else
                    <div class="alert alert-mc-warning im-inline-alert"><i class="fas fa-circle-info mr-2"></i> Industry recipe data is <strong>not loaded</strong>. Run <code>php artisan industry-manager:import-recipes</code> to populate it. Until then the recipe-powered tools (calculator, schematics, invention, reactions) stay empty; live-data pages work normally.</div>
                @endif

                <p class="im-text-muted">
                    <strong>Source:</strong> {{ $sde['source_label'] }}.
                    @if($sde['core_version']) Core SDE build: <code>{{ $sde['core_version'] }}</code>.@endif
                </p>

                <div class="im-result-grid mb-3">
                    <div class="im-result-stat">
                        <div class="im-result-label">Manufacturing blueprints</div>
                        <div class="im-result-value">{{ number_format($coverage['manufacturing_blueprints']) }}</div>
                    </div>
                    <div class="im-result-stat">
                        <div class="im-result-label">Reaction formulas</div>
                        <div class="im-result-value">{{ number_format($coverage['reaction_formulas']) }}</div>
                    </div>
                    <div class="im-result-stat">
                        <div class="im-result-label">Material rows</div>
                        <div class="im-result-value">{{ number_format($coverage['material_rows']) }}</div>
                    </div>
                    <div class="im-result-stat">
                        <div class="im-result-label">Cache driver</div>
                        <div class="im-result-value">{{ $cacheDriver }}</div>
                    </div>
                </div>

                <h5 class="im-subhead">SDE tables</h5>
                <table class="table im-table im-table-compact">
                    <thead><tr><th>Table</th><th class="text-center">Present</th><th class="text-right">Rows</th></tr></thead>
                    <tbody>
                        @foreach($sde['tables'] as $name => $info)
                            <tr>
                                <td><code>{{ $name }}</code></td>
                                <td class="text-center">
                                    @if($info['exists'])
                                        <span class="im-badge im-status-ready">yes</span>
                                    @else
                                        <span class="im-badge im-status-cancelled">no</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($info['rows']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- How it works --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-circle-info mr-2"></i> How Industry Manager gets its data</h3>
            </div>
            <div class="card-body">
                <ul class="im-text-muted">
                    <li><strong>No ESI calls.</strong> Blueprints, jobs, structures and assets all come from data SeAT already syncs.</li>
                    <li><strong>Pricing / ISK</strong> is not part of this version. It arrives with Manager Core integration in a later update.</li>
                    <li>Persisted preferences (default ME, decryptor, etc.) are planned but not stored yet.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@stop
