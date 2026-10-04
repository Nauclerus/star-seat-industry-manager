@extends('web::layouts.grids.12')

@section('title', 'Invention — Industry Manager')
@section('page_header', 'Invention')

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
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-flask mr-2"></i> Invention</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('industry-manager.invention') }}" class="im-calc-form">
                    <div class="form-row align-items-end">
                        <div class="form-group col-md-6">
                            <label>T1 Blueprint type ID</label>
                            <input type="number" name="bp" class="form-control" value="{{ $bp }}" placeholder="The T1 blueprint you invent from" required>
                            <small class="im-text-muted">Enter the <strong>T1 blueprint</strong> type ID (the source of the invention job), or pick one below.</small>
                        </div>
                        <div class="form-group col-md-3">
                            <button type="submit" class="btn btn-im-primary btn-block"><i class="fas fa-flask mr-1"></i> Look up</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if($sdeReady && $bp && !$data)
            <div class="alert alert-warning">
                No invention recipe found for blueprint type ID <code>{{ $bp }}</code>. Make sure it's a T1 blueprint that can be reverse-engineered/invented (most T1 ship/module BPs can).
            </div>
        @endif

        @if($data)
            <div class="row">
                <div class="col-lg-5">
                    {{-- Datacores + skills + time --}}
                    <div class="card card-dark">
                        <div class="card-header">
                            <h4 class="card-title mb-0"><i class="fas fa-vials mr-2"></i> {{ $data['t1_blueprint_name'] }}</h4>
                        </div>
                        <div class="card-body">
                            <div class="im-result-stat mb-3">
                                <div class="im-result-label">Base invention time</div>
                                <div class="im-result-value">{{ \IndustryManager\Helpers\Format::duration($data['time']) }}</div>
                            </div>

                            <h5 class="im-subhead">Datacores &amp; materials</h5>
                            @if(empty($data['datacores']))
                                <p class="im-text-muted">None listed.</p>
                            @else
                                <table class="table im-table im-table-compact">
                                    <tbody>
                                        @foreach($data['datacores'] as $dc)
                                            <tr>
                                                <td>{{ $dc['name'] }}</td>
                                                <td class="text-right">&times;{{ number_format($dc['quantity']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif

                            @if(!empty($data['skills']))
                                <h5 class="im-subhead">Skills</h5>
                                <div>
                                    @foreach($data['skills'] as $sk)
                                        <span class="im-badge im-badge-skill">{{ $sk['name'] }} {{ $sk['level'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    {{-- Outcome + decryptor controls --}}
                    <div class="card card-dark">
                        <div class="card-header">
                            <h4 class="card-title mb-0"><i class="fas fa-dice mr-2"></i> Outcome</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-row align-items-end mb-3">
                                <div class="form-group col-md-7 mb-0">
                                    <label>Decryptor</label>
                                    <select id="im-decryptor" class="form-control">
                                        @foreach($decryptors as $id => $d)
                                            <option value="{{ $id }}">{{ $d['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-5 mb-0">
                                    <div class="custom-control custom-checkbox mt-4">
                                        <input type="checkbox" class="custom-control-input" id="im-skills-v">
                                        <label class="custom-control-label im-text-light" for="im-skills-v">Invention skills at V (+45.8%)</label>
                                    </div>
                                </div>
                            </div>

                            @foreach($data['outcomes'] as $i => $oc)
                                <div class="im-invent-outcome"
                                     data-base-prob="{{ $oc['base_probability'] }}"
                                     data-base-runs="{{ $oc['base_runs'] }}">
                                    <div class="im-invent-product">{{ $oc['product_name'] }}</div>
                                    <div class="im-invent-grid">
                                        <div class="im-invent-stat">
                                            <div class="im-result-label">Success chance</div>
                                            <div class="im-result-value im-out-prob">—</div>
                                        </div>
                                        <div class="im-invent-stat">
                                            <div class="im-result-label">BPC runs</div>
                                            <div class="im-result-value im-out-runs">—</div>
                                        </div>
                                        <div class="im-invent-stat">
                                            <div class="im-result-label">BPC ME</div>
                                            <div class="im-result-value im-out-me">—</div>
                                        </div>
                                        <div class="im-invent-stat">
                                            <div class="im-result-label">BPC TE</div>
                                            <div class="im-result-value im-out-te">—</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            <p class="im-text-muted mt-2"><i class="fas fa-circle-info mr-1"></i> ME/TE shown are the invented copy's values (base ME {{ $baseMe }} / TE {{ $baseTe }} plus decryptor). Datacore/decryptor ISK cost arrives in v1.1.</p>
                        </div>
                    </div>
                </div>
            </div>
        @elseif(!$bp && !$picker->isEmpty())
            <div class="card card-dark">
                <div class="card-header"><h4 class="card-title mb-0"><i class="fas fa-hand-pointer mr-2"></i> Pick a blueprint</h4></div>
                <div class="card-body">
                    <p class="im-text-muted">Choose one of your blueprints to check its invention:</p>
                    <div class="im-picker-grid">
                        @foreach($picker->take(60) as $p)
                            <a href="{{ route('industry-manager.invention', ['bp' => $p['type_id']]) }}" class="im-picker-item">
                                <span class="im-picker-name">{{ $p['type_name'] }}</span>
                                <span class="im-picker-meta">{{ $p['total'] }} owned</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>
@stop

@push('javascript')
<script>
    (function () {
        var DEC = @json($decryptors);
        var SKILL_V = {{ $skillMultV }};
        var BASE_ME = {{ $baseMe }};
        var BASE_TE = {{ $baseTe }};

        function recompute() {
            var sel = document.getElementById('im-decryptor');
            if (!sel) return;
            var d = DEC[sel.value] || DEC[0];
            var skills = document.getElementById('im-skills-v').checked ? SKILL_V : 1.0;

            document.querySelectorAll('.im-invent-outcome').forEach(function (row) {
                var baseProb = parseFloat(row.getAttribute('data-base-prob')) || 0;
                var baseRuns = parseInt(row.getAttribute('data-base-runs')) || 0;

                var prob = baseProb * d.prob * skills;
                if (prob > 1) prob = 1;

                row.querySelector('.im-out-prob').textContent = (prob * 100).toFixed(1) + '%';
                row.querySelector('.im-out-runs').textContent = (baseRuns + (d.runs | 0));
                row.querySelector('.im-out-me').textContent = (BASE_ME + (d.me | 0));
                row.querySelector('.im-out-te').textContent = (BASE_TE + (d.te | 0));
            });
        }

        var s = document.getElementById('im-decryptor');
        var c = document.getElementById('im-skills-v');
        if (s) s.addEventListener('change', recompute);
        if (c) c.addEventListener('change', recompute);
        recompute();
    })();
</script>
@endpush
