@extends('web::layouts.grids.12')

@section('title', 'Blueprint — Industry Manager')
@section('page_header', 'Blueprint Detail')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=6">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-scroll mr-2"></i> Blueprint #{{ $type_id }}</h3>
            </div>
            <div class="card-body">
                <p class="im-text-muted">{{ trans('industry-manager::common.coming_soon') }} (Sprint 1).</p>
                <p class="im-text-muted">Will show: materials at applied ME, base + adjusted time, products per run, activity (manufacturing / invention / reaction / copy / research_me / research_te), and skill requirements. Pulled from <code>industryActivity*</code> SDE tables shipped by this plugin's own seeder.</p>
            </div>
        </div>
    </div>
</div>
@stop
