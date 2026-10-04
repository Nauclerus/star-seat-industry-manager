@extends('web::layouts.grids.12')

@section('title', 'PI Schematics — Industry Manager')
@section('page_header', 'PI Schematics')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=5">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager im-pi">

        @unless($piReady)
            @include('industry-manager::_partials.sde_notice')
        @endunless

        @if($recipe && $tree)
            <div class="card card-dark">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">
                        <span class="im-tier-badge im-tier-{{ $recipe['output']['tier'] ?? 'x' }}">{{ \IndustryManager\Helpers\PiTier::shortLabel($recipe['output']['tier'] ?? null) }}</span>
                        {{ $recipe['output']['name'] ?? $recipe['name'] }}
                    </h3>
                    <a href="{{ route('industry-manager.pi.schematics') }}" class="im-mini-btn"><i class="fas fa-arrow-left mr-1"></i> All schematics</a>
                </div>
                <div class="card-body">
                    <div class="im-result-grid mb-3">
                        <div class="im-result-stat"><div class="im-result-label">Output / cycle</div><div class="im-result-value">{{ number_format($recipe['output']['qty'] ?? 0) }}</div></div>
                        <div class="im-result-stat"><div class="im-result-label">Cycle time</div><div class="im-result-value">{{ \IndustryManager\Helpers\Format::duration($recipe['cycle_time'] ?? 0) }}</div></div>
                        <div class="im-result-stat"><div class="im-result-label">Direct inputs</div><div class="im-result-value">{{ count($recipe['inputs']) }}</div></div>
                    </div>

                    <div class="row">
                        <div class="col-lg-5">
                            <h5 class="im-subhead">Base materials (full tree)</h5>
                            <table class="table im-table im-table-compact">
                                <thead><tr><th>Tier</th><th>Material</th><th class="text-right">Quantity</th></tr></thead>
                                <tbody>
                                    @foreach($tree['base_materials'] as $bm)
                                        <tr>
                                            <td><span class="im-tier-badge im-tier-{{ $bm['tier'] ?? 'x' }}">{{ \IndustryManager\Helpers\PiTier::shortLabel($bm['tier'] ?? null) }}</span></td>
                                            <td>{{ $bm['name'] }}</td>
                                            <td class="text-right">{{ number_format($bm['quantity']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="col-lg-7">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <h5 class="im-subhead mb-0">Production tree</h5>
                                <div class="im-tree-controls">
                                    <button type="button" class="im-mini-btn" onclick="imPiTree(true)"><i class="fas fa-angles-down"></i></button>
                                    <button type="button" class="im-mini-btn" onclick="imPiTree(false)"><i class="fas fa-angles-up"></i></button>
                                </div>
                            </div>
                            <div class="im-tree" id="im-pi-tree">
                                @include('industry-manager::pi._tree_node', ['node' => $tree['root'], 'depth' => 0])
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="card card-dark">
                <div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-sitemap mr-2"></i> PI Schematics</h3></div>
                <div class="card-body">
                    @if(!$piReady)
                        <p class="im-text-muted">PI schematic data isn't loaded in this build, so there's nothing to browse here yet. Your planetary colonies, extractors and factories still appear under <a href="{{ route('industry-manager.pi.overview') }}">Planetary Industry</a>.</p>
                    @elseif($schematics->isEmpty())
                        <p class="im-text-muted">No schematics found.</p>
                    @else
                        <p class="im-text-muted">{{ number_format($schematics->count()) }} schematic(s). Click one to see its full P-tier input tree down to raw materials.</p>
                        <div class="table-responsive">
                            <table id="im-pi-schematics" class="table im-table" style="width:100%">
                                <thead><tr><th>Tier</th><th>Output</th><th class="text-center">Qty/cycle</th><th class="text-center">Cycle</th><th class="text-right">Action</th></tr></thead>
                                <tbody>
                                    @foreach($schematics as $s)
                                        <tr>
                                            <td data-order="{{ $s['tier'] ?? 9 }}"><span class="im-tier-badge im-tier-{{ $s['tier'] ?? 'x' }}">{{ \IndustryManager\Helpers\PiTier::shortLabel($s['tier'] ?? null) }}</span></td>
                                            <td><span class="im-bp-name">{{ $s['output_name'] }}</span></td>
                                            <td class="text-center">{{ number_format($s['output_qty']) }}</td>
                                            <td class="text-center im-text-muted">{{ \IndustryManager\Helpers\Format::duration($s['cycle_time']) }}</td>
                                            <td class="text-right"><a href="{{ route('industry-manager.pi.schematics', ['out' => $s['output_type_id']]) }}" class="btn btn-sm btn-im-primary"><i class="fas fa-sitemap mr-1"></i> Tree</a></td>
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
    function imPiTree(open) {
        document.querySelectorAll('#im-pi-tree details.im-tree-branch').forEach(function (d) { d.open = open; });
    }
    $(function () {
        if ($.fn.DataTable && document.getElementById('im-pi-schematics')) {
            $('#im-pi-schematics').DataTable({ order: [[0, 'asc'], [1, 'asc']], pageLength: 25, columnDefs: [{ orderable: false, targets: [4] }] });
        }
    });
</script>
@endpush
