@extends('web::layouts.grids.12')

@section('title', 'Blueprints — Industry Manager')
@section('page_header', 'Blueprint Library')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=4">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        @unless($sdeReady)
            @include('industry-manager::_partials.sde_notice')
        @endunless

        <div class="card card-dark">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-scroll mr-2"></i> Blueprint Library</h3>
                <div class="im-owner-filter">
                    <a href="{{ route('industry-manager.blueprints') }}"
                       class="im-pill {{ $ownerFilter ? '' : 'active' }}">All</a>
                    <a href="{{ route('industry-manager.blueprints', ['owner' => 'character']) }}"
                       class="im-pill {{ $ownerFilter === 'character' ? 'active' : '' }}">My Characters</a>
                    <a href="{{ route('industry-manager.blueprints', ['owner' => 'corporation']) }}"
                       class="im-pill {{ $ownerFilter === 'corporation' ? 'active' : '' }}">My Corporations</a>
                </div>
            </div>
            <div class="card-body">
                <p class="im-text-muted">
                    Showing {{ number_format($groups->count()) }} blueprint type(s) across {{ number_format($totalBlueprints) }} blueprint(s) you can see. Originals and copies are grouped per type. Click <strong>Calculate</strong> to break down what a blueprint needs to build.
                </p>

                @if($groups->isEmpty())
                    <div class="im-empty-state">
                        <i class="fas fa-scroll"></i>
                        <p>No blueprints found for your characters or corporations.</p>
                        <p class="im-text-muted">If you just added an API key, SeAT may still be syncing your blueprints. Check back shortly.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table id="im-blueprints-table" class="table im-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Blueprint</th>
                                    <th class="text-center">Originals</th>
                                    <th class="text-center">Copies</th>
                                    <th class="text-center">Best ME</th>
                                    <th class="text-center">Best TE</th>
                                    <th>Owner(s)</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($groups as $g)
                                    <tr>
                                        <td>
                                            <span class="im-bp-name">{{ $g['type_name'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            @if($g['bpo_count'] > 0)
                                                <span class="im-badge im-badge-bpo">{{ $g['bpo_count'] }}</span>
                                            @else
                                                <span class="im-text-muted">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($g['bpc_count'] > 0)
                                                <span class="im-badge im-badge-bpc">{{ $g['bpc_count'] }}</span>
                                            @else
                                                <span class="im-text-muted">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="text-center" data-order="{{ $g['best_me'] }}">
                                            <span class="im-eff im-eff-me">{{ $g['best_me'] }}</span>
                                        </td>
                                        <td class="text-center" data-order="{{ $g['best_te'] }}">
                                            <span class="im-eff im-eff-te">{{ $g['best_te'] }}</span>
                                        </td>
                                        <td>
                                            <span class="im-text-muted">{{ implode(', ', array_slice($g['owners'], 0, 3)) }}@if(count($g['owners']) > 3) +{{ count($g['owners']) - 3 }}@endif</span>
                                        </td>
                                        <td class="text-right">
                                            <a href="{{ route('industry-manager.calculator', ['bp' => $g['type_id'], 'me' => $g['best_me']]) }}"
                                               class="btn btn-sm btn-im-primary">
                                                <i class="fas fa-calculator mr-1"></i> Calculate
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
    </div>
</div>
@stop

@push('javascript')
<script>
    $(function () {
        if ($.fn.DataTable && document.getElementById('im-blueprints-table')) {
            $('#im-blueprints-table').DataTable({
                order: [[0, 'asc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                columnDefs: [
                    { orderable: false, targets: [6] }
                ]
            });
        }
    });
</script>
@endpush
