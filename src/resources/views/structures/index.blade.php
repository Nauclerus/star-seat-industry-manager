@extends('web::layouts.grids.12')

@section('title', 'Structures — Industry Manager')
@section('page_header', 'Industry Structures')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=7">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-building mr-2"></i> Industry Structures</h3>
            </div>
            <div class="card-body">
                @if($structures->isEmpty())
                    <div class="im-empty-state">
                        <i class="fas fa-building"></i>
                        <p>No industry structures found for your corporations.</p>
                        <p class="im-text-muted">Structures are listed when a service module in them provides an industry activity.</p>
                    </div>
                @else
                    <div class="im-structure-grid">
                        @foreach($structures as $st)
                            <div class="im-structure-card">
                                <div class="im-structure-head">
                                    <div>
                                        <span class="im-structure-name">{{ $st['name'] }}</span>
                                        <div class="im-structure-sub">{{ $st['type_name'] }} · {{ $st['class'] }}</div>
                                    </div>
                                    <span class="im-badge im-badge-size">{{ $st['size'] }}</span>
                                </div>

                                <div class="im-structure-meta">
                                    <span><i class="fas fa-location-dot mr-1"></i>{{ $st['system_name'] }}</span>
                                    @if($st['security'] !== null)
                                        <span class="im-sec im-sec-{{ \Illuminate\Support\Str::slug($st['security_class']) }}">{{ number_format($st['security'], 1) }} {{ $st['security_class'] }}</span>
                                    @endif
                                    <span class="im-text-muted">rig &times;{{ $st['security_multiplier'] }}</span>
                                    @if($st['scope'] === 'alliance')
                                        <span class="im-badge im-badge-scope">Alliance</span>
                                    @endif
                                    @if($st['access'] === \IndustryManager\Services\StructureAccess::DENIED)
                                        <span class="im-badge im-badge-denied" title="Docking denied for your characters">No docking</span>
                                    @endif
                                </div>

                                <div class="im-structure-services">
                                    <div class="im-result-label mb-1">Services</div>
                                    @if(empty($st['services']))
                                        <div class="im-text-muted">No service modules fitted.</div>
                                    @else
                                        @foreach($st['services'] as $svc)
                                            <div class="im-service-row">
                                                <span class="im-service-name">{{ $svc['name'] ?: ('Module ' . $svc['type_id']) }}</span>
                                                @if($svc['quantity'] > 1)
                                                    <span class="im-text-muted">&times;{{ $svc['quantity'] }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif

                                    <div class="im-activity-row">
                                        @forelse($st['activities'] as $activityId => $line)
                                            <span class="im-badge im-badge-activity">
                                                {{ \IndustryManager\Helpers\IndustryActivity::name($activityId) }}
                                            </span>
                                        @empty
                                            <span class="im-badge im-badge-gap">
                                                <i class="fas fa-ban mr-1"></i> No industry activity
                                            </span>
                                        @endforelse

                                        @foreach($st['blocked'] as $activityId => $max)
                                            <span class="im-badge im-badge-gap" title="{{ \IndustryManager\Helpers\IndustryActivity::name($activityId) }} needs security {{ $max }} or lower here">
                                                {{ \IndustryManager\Helpers\IndustryActivity::name($activityId) }} needs {{ $max }} or lower
                                            </span>
                                        @endforeach
                                    </div>
                                </div>

                                @if(!empty($st['bonuses']))
                                    <div class="im-structure-bonuses">
                                        <div class="im-result-label mb-1">Structure bonus</div>
                                        @foreach($st['bonuses'] as $activityId => $bonus)
                                            <div class="im-bonus-row">
                                                <span class="im-bonus-activity">{{ \IndustryManager\Helpers\IndustryActivity::name($activityId) }}</span>
                                                @if($bonus['material'] < 1)
                                                    <span class="im-rig-bonus">-{{ round((1 - $bonus['material']) * 100, 1) }}% materials</span>
                                                @endif
                                                @if($bonus['cost'] < 1)
                                                    <span class="im-rig-bonus">-{{ round((1 - $bonus['cost']) * 100, 1) }}% cost</span>
                                                @endif
                                                @if($bonus['time'] < 1)
                                                    <span class="im-rig-bonus im-rig-te">-{{ round((1 - $bonus['time']) * 100, 1) }}% time</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="im-structure-rigs">
                                    <div class="im-result-label mb-1">Fitted rigs ({{ count($st['rigs']) }})</div>
                                    @if(empty($st['rigs']))
                                        <div class="im-text-muted">No rigs fitted.</div>
                                    @else
                                        @foreach($st['rigs'] as $rig)
                                            <div class="im-rig-row">
                                                <span class="im-rig-name">{{ $rig['name'] }}</span>
                                                @if($rig['bonus'] === 'me')
                                                    <span class="im-rig-bonus" title="Material Efficiency bonus, security-adjusted">
                                                        -{{ $rig['effective'] }}% ME
                                                    </span>
                                                @elseif($rig['bonus'] === 'te')
                                                    <span class="im-rig-bonus im-rig-te" title="Time Efficiency bonus, security-adjusted">
                                                        -{{ $rig['effective'] }}% TE
                                                    </span>
                                                @elseif($rig['bonus'] === 'cost')
                                                    <span class="im-rig-bonus" title="Job cost bonus, security-adjusted">
                                                        -{{ $rig['effective'] }}% cost
                                                    </span>
                                                @else
                                                    <span class="im-text-muted">no industry bonus</span>
                                                @endif
                                            </div>
                                        @endforeach

                                        @if($st['me_bonus'] > 0 || $st['te_bonus'] > 0 || $st['cost_bonus'] > 0)
                                            <div class="im-rig-row im-rig-effective">
                                                <span class="im-rig-name">Effective for this fit</span>
                                                @if($st['me_bonus'] > 0)<span class="im-rig-bonus">-{{ $st['me_bonus'] }}% ME</span>@endif
                                                @if($st['te_bonus'] > 0)<span class="im-rig-bonus im-rig-te">-{{ $st['te_bonus'] }}% TE</span>@endif
                                                @if($st['cost_bonus'] > 0)<span class="im-rig-bonus">-{{ $st['cost_bonus'] }}% cost</span>@endif
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@stop
