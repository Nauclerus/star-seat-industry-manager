@extends('web::layouts.grids.12')

@section('title', trans('industry-manager::common.industry_manager'))
@section('page_header', trans('industry-manager::common.industry_manager'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=4">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        @unless($stats['sde_ready'])
            @include('industry-manager::_partials.sde_notice')
        @endunless

        <div class="row">
            <div class="col-lg-3 col-md-6">
                <div class="im-stat-card">
                    <div class="im-stat-icon gradient"><i class="fas fa-scroll"></i></div>
                    <div class="im-stat-label">Blueprint Types</div>
                    <div class="im-stat-value">{{ number_format($stats['blueprint_types']) }}</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="im-stat-card">
                    <div class="im-stat-icon gradient"><i class="fas fa-layer-group"></i></div>
                    <div class="im-stat-label">Total Blueprints</div>
                    <div class="im-stat-value">{{ number_format($stats['total_blueprints']) }}</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="im-stat-card">
                    <div class="im-stat-icon im-bpo"><i class="fas fa-certificate"></i></div>
                    <div class="im-stat-label">Originals (BPO)</div>
                    <div class="im-stat-value">{{ number_format($stats['bpo_count']) }}</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="im-stat-card">
                    <div class="im-stat-icon im-bpc"><i class="fas fa-copy"></i></div>
                    <div class="im-stat-label">Copies (BPC)</div>
                    <div class="im-stat-value">{{ number_format($stats['bpc_count']) }}</div>
                </div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-industry mr-2"></i> Industry Manager</h3>
            </div>
            <div class="card-body">
                <p class="im-text-muted">Your blueprints, what they need to build, and where to build them. All read from data SeAT already syncs (no ESI calls from this plugin).</p>
                <div class="im-quicklinks">
                    <a href="{{ route('industry-manager.blueprints') }}" class="im-quicklink">
                        <i class="fas fa-scroll"></i>
                        <span class="im-quicklink-title">Blueprint Library</span>
                        <span class="im-quicklink-sub">Browse your originals &amp; copies</span>
                    </a>
                    <a href="{{ route('industry-manager.calculator') }}" class="im-quicklink">
                        <i class="fas fa-calculator"></i>
                        <span class="im-quicklink-title">Production Calculator</span>
                        <span class="im-quicklink-sub">Materials &amp; build tree at your ME/TE</span>
                    </a>
                    <a href="{{ route('industry-manager.structures') }}" class="im-quicklink">
                        <i class="fas fa-building"></i>
                        <span class="im-quicklink-title">Structures</span>
                        <span class="im-quicklink-sub">Rigs &amp; bonuses (coming soon)</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@stop
