@extends('web::layouts.grids.12')

@section('title', $project->name . ' — PI Projects')
@section('page_header', $project->name)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=5">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager im-pi">

        @if(session('success'))
            <div class="alert im-inline-alert alert-mc-success"><i class="fas fa-circle-check mr-2"></i>{{ session('success') }}</div>
        @endif

        <div class="card card-dark">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-clipboard-list mr-2"></i> {{ $project->name }}</h3>
                <div>
                    <a href="{{ route('industry-manager.pi.projects.index') }}" class="im-mini-btn"><i class="fas fa-arrow-left mr-1"></i> All projects</a>
                    <form method="POST" action="{{ route('industry-manager.pi.projects.destroy', ['id' => $project->id]) }}" class="d-inline" onsubmit="return confirm('Delete this project?');">
                        @csrf
                        <button type="submit" class="im-mini-btn im-mini-danger"><i class="fas fa-trash mr-1"></i> Delete</button>
                    </form>
                </div>
            </div>
            @if($project->description)
                <div class="card-body pb-0"><p class="im-text-muted">{{ $project->description }}</p></div>
            @endif
        </div>

        <div class="row">
            {{-- Objectives --}}
            <div class="col-lg-6">
                <div class="card card-dark">
                    <div class="card-header"><h4 class="card-title mb-0"><i class="fas fa-bullseye mr-2"></i> Objectives</h4></div>
                    <div class="card-body">
                        @if(empty($objectives))
                            <p class="im-text-muted">No objectives yet. Add a production target below.</p>
                        @else
                            <table class="table im-table im-table-compact">
                                <thead><tr><th>Product</th><th class="text-right">Target</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($objectives as $o)
                                        <tr>
                                            <td>{{ $o['name'] }}</td>
                                            <td class="text-right">{{ number_format($o['target']) }}</td>
                                            <td class="text-right">
                                                <form method="POST" action="{{ route('industry-manager.pi.projects.objectives.remove', ['id' => $project->id, 'objectiveId' => $o['id']]) }}" onsubmit="return confirm('Remove objective?');">
                                                    @csrf
                                                    <button class="im-icon-btn" title="Remove"><i class="fas fa-times"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        <form method="POST" action="{{ route('industry-manager.pi.projects.objectives.add', ['id' => $project->id]) }}" class="form-row align-items-end mt-2">
                            @csrf
                            <div class="form-group col-7 mb-1">
                                <label class="im-text-muted">Product</label>
                                <select name="type_id" class="form-control" required>
                                    <option value="">Select PI product…</option>
                                    @foreach($outputs as $opt)
                                        <option value="{{ $opt['output_type_id'] }}">{{ \IndustryManager\Helpers\PiTier::shortLabel($opt['tier'] ?? null) }} · {{ $opt['output_name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-3 mb-1">
                                <label class="im-text-muted">Target</label>
                                <input type="number" name="target_quantity" class="form-control" min="0" value="0" required>
                            </div>
                            <div class="form-group col-2 mb-1">
                                <button class="btn btn-im-primary btn-block"><i class="fas fa-plus"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Assigned planets --}}
            <div class="col-lg-6">
                <div class="card card-dark">
                    <div class="card-header"><h4 class="card-title mb-0"><i class="fas fa-globe mr-2"></i> Assigned Planets</h4></div>
                    <div class="card-body">
                        @if(empty($assigned_planets))
                            <p class="im-text-muted">No planets assigned. Assign colonies that will supply this project.</p>
                        @else
                            <table class="table im-table im-table-compact">
                                <thead><tr><th>Character</th><th>Planet</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($assigned_planets as $ap)
                                        <tr>
                                            <td class="im-text-muted">{{ $ap['character_name'] }}</td>
                                            <td>{{ $ap['planet_name'] }}</td>
                                            <td class="text-right">
                                                <form method="POST" action="{{ route('industry-manager.pi.projects.planets.unassign', ['id' => $project->id, 'rowId' => $ap['id']]) }}">
                                                    @csrf
                                                    <button class="im-icon-btn" title="Unassign"><i class="fas fa-times"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        @if(!empty($available_planets))
                            <form method="POST" action="{{ route('industry-manager.pi.projects.planets.assign', ['id' => $project->id]) }}" class="form-row align-items-end mt-2">
                                @csrf
                                <div class="form-group col-9 mb-1">
                                    <label class="im-text-muted">Add a colony</label>
                                    <select name="planet" class="form-control" required>
                                        <option value="">Select planet…</option>
                                        @foreach($available_planets as $pl)
                                            <option value="{{ $pl['character_id'] }}:{{ $pl['planet_id'] }}">{{ $pl['character_name'] }} — {{ $pl['planet_name'] }} ({{ $pl['planet_type'] }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-3 mb-1">
                                    <button class="btn btn-im-primary btn-block"><i class="fas fa-plus"></i></button>
                                </div>
                            </form>
                        @else
                            <p class="im-text-muted mt-2" style="font-size:0.8rem;">All your colonies are assigned (or none are synced).</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Required materials vs supply --}}
        <div class="card card-dark">
            <div class="card-header"><h4 class="card-title mb-0"><i class="fas fa-scale-balanced mr-2"></i> Required Materials &amp; Coverage</h4></div>
            <div class="card-body">
                @if(empty($required))
                    <p class="im-text-muted">Add objectives to see the full PI material requirement.</p>
                @else
                    <p class="im-text-muted">Every material the objectives need across all tiers, and whether your assigned planets currently produce it. Supply is the live output rate of assigned colonies.</p>
                    <table class="table im-table im-table-compact">
                        <thead><tr><th>Tier</th><th>Material</th><th class="text-right">Required (total)</th><th class="text-right">Assigned supply /hr</th><th class="text-center">Coverage</th></tr></thead>
                        <tbody>
                            @foreach($required as $r)
                                <tr>
                                    <td><span class="im-tier-badge im-tier-{{ $r['tier'] ?? 'x' }}">{{ \IndustryManager\Helpers\PiTier::shortLabel($r['tier'] ?? null) }}</span></td>
                                    <td>{{ $r['name'] }}</td>
                                    <td class="text-right">{{ number_format($r['quantity']) }}</td>
                                    <td class="text-right">{{ $r['supplied_rate'] ? number_format($r['supplied_rate']) : '—' }}</td>
                                    <td class="text-center">
                                        @if($r['covered'])
                                            <span class="im-badge im-status-ready"><i class="fas fa-check"></i></span>
                                        @else
                                            <span class="im-badge im-status-cancelled"><i class="fas fa-xmark"></i> gap</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="im-text-muted" style="font-size:0.8rem;"><i class="fas fa-circle-info mr-1"></i> Required is the absolute amount to hit your targets once. Supply is a per-hour rate from assigned planets, shown for coverage; full rate-vs-target reconciliation comes later.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@stop
