@extends('web::layouts.grids.12')

@section('title', 'Structures — Industry Manager')
@section('page_header', 'Industry Structures')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=4">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-building mr-2"></i> Industry Structures</h3>
            </div>
            <div class="card-body">
                <p class="im-text-muted">
                    Your corporation's Engineering Complexes and Refineries, with fitted rigs. Bonuses are read from the rig's own dogma
                    attributes (2593 TE / 2594 ME / 2595 job cost) and scaled by the security multiplier stored on that rig
                    (2355 / 2356 / 2357), which is why the same T1 ME rig reads 2% in high-sec, 3.8% in low-sec and 4.2% in null-sec.
                </p>

                @if($structures->isEmpty())
                    <div class="im-empty-state">
                        <i class="fas fa-building"></i>
                        <p>No industry structures found for your corporations.</p>
                        <p class="im-text-muted">Only Engineering Complexes (Raitaru/Azbel/Sotiyo) and Refineries (Athanor/Tatara) are shown. SeAT must have structure + asset data synced for your corp.</p>
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
                                </div>

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
                                                    <span class="im-text-muted">{{ $rig['raw'] }} × {{ $rig['multiplier'] }}</span>
                                                @elseif($rig['bonus'] === 'te')
                                                    <span class="im-rig-bonus im-rig-te" title="Time Efficiency bonus, security-adjusted">
                                                        -{{ $rig['effective'] }}% TE
                                                    </span>
                                                    <span class="im-text-muted">{{ $rig['raw'] }} × {{ $rig['multiplier'] }}</span>
                                                @elseif($rig['bonus'] === 'cost')
                                                    <span class="im-rig-bonus" title="Job cost bonus, security-adjusted">
                                                        -{{ $rig['effective'] }}% cost
                                                    </span>
                                                    <span class="im-text-muted">{{ $rig['raw'] }} × {{ $rig['multiplier'] }}</span>
                                                @else
                                                    <span class="im-text-muted">no bonus attribute</span>
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
