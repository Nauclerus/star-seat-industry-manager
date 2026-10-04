@extends('web::layouts.grids.12')

@section('title', 'Reactions — Industry Manager')
@section('page_header', 'Reactions')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=4">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        @unless($sdeReady)
            @include('industry-manager::_partials.sde_notice')
        @endunless

        @if($recipe)
            @php $runs = 1; @endphp
            <div class="card card-dark">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><i class="fas fa-atom mr-2"></i> {{ $productName ?? $recipe['blueprint_name'] }}</h3>
                    <a href="{{ route('industry-manager.reactions') }}" class="im-mini-btn"><i class="fas fa-arrow-left mr-1"></i> All formulas</a>
                </div>
                <div class="card-body">
                    <div class="im-result-grid mb-3">
                        <div class="im-result-stat">
                            <div class="im-result-label">Formula</div>
                            <div class="im-result-value">{{ $recipe['blueprint_name'] }}</div>
                        </div>
                        <div class="im-result-stat">
                            <div class="im-result-label">Output / cycle</div>
                            <div class="im-result-value">{{ number_format($recipe['product_quantity'] ?? 0) }}</div>
                        </div>
                        <div class="im-result-stat">
                            <div class="im-result-label">Cycle time</div>
                            <div class="im-result-value">{{ \IndustryManager\Helpers\Format::duration($recipe['time'] ?? 0) }}</div>
                        </div>
                    </div>

                    <h5 class="im-subhead">Inputs / cycle</h5>
                    <table class="table im-table im-table-compact">
                        <thead><tr><th>Material</th><th class="text-right">Quantity</th></tr></thead>
                        <tbody>
                            @foreach($recipe['materials'] as $m)
                                <tr>
                                    <td>{{ $m['name'] }}</td>
                                    <td class="text-right">{{ number_format($m['base_quantity']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if(!empty($recipe['skills']))
                        <h5 class="im-subhead">Skills</h5>
                        <div>
                            @foreach($recipe['skills'] as $sk)
                                <span class="im-badge im-badge-skill">{{ $sk['name'] }} {{ $sk['level'] }}</span>
                            @endforeach
                        </div>
                    @endif
                    <p class="im-text-muted mt-2"><i class="fas fa-circle-info mr-1"></i> Quantities are per reaction cycle. Structure/rig material bonuses and ISK valuation arrive in later updates.</p>
                </div>
            </div>
        @else
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title mb-0"><i class="fas fa-atom mr-2"></i> Reaction Formulas</h3>
                </div>
                <div class="card-body">
                    @if($formulas->isEmpty())
                        <p class="im-text-muted">No reaction formulas available. Load the SDE industry tables first (see the notice above if shown).</p>
                    @else
                        <p class="im-text-muted">{{ number_format($formulas->count()) }} reaction formula(s). Click one to see its inputs, output, and cycle time.</p>
                        <div class="table-responsive">
                            <table id="im-reactions-table" class="table im-table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Output</th>
                                        <th>Formula</th>
                                        <th class="text-center">Output / cycle</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($formulas as $f)
                                        <tr>
                                            <td><span class="im-bp-name">{{ $f['output_name'] }}</span></td>
                                            <td class="im-text-muted">{{ $f['formula_name'] }}</td>
                                            <td class="text-center">{{ number_format($f['output_qty']) }}</td>
                                            <td class="text-right">
                                                <a href="{{ route('industry-manager.reactions', ['formula' => $f['formula_type_id']]) }}" class="btn btn-sm btn-im-primary">
                                                    <i class="fas fa-flask-vial mr-1"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif

    </div>
</div>
@stop

@push('javascript')
<script>
    $(function () {
        if ($.fn.DataTable && document.getElementById('im-reactions-table')) {
            $('#im-reactions-table').DataTable({ order: [[0, 'asc']], pageLength: 25, columnDefs: [{ orderable: false, targets: [3] }] });
        }
    });
</script>
@endpush
