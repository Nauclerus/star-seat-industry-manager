@extends('web::layouts.grids.12')

@section('title', 'Planetary Industry — Industry Manager')
@section('page_header', 'Planetary Industry')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=5">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager im-pi">

        @unless($piReady)
            @include('industry-manager::_partials.sde_notice')
        @endunless

        @if($colonies->isEmpty())
            <div class="card card-dark"><div class="card-body">
                <div class="im-empty-state">
                    <i class="fas fa-globe"></i>
                    <p>No planetary colonies found for your characters.</p>
                    <p class="im-text-muted">Industry Manager reads colonies SeAT has synced. If you run PI, make sure your characters' planets scope is authorized and synced.</p>
                </div>
            </div></div>
        @else
            <div class="row">
                <div class="col-md-3 col-6"><div class="im-stat-card im-stat-mini"><div class="im-stat-label">Colonies</div><div class="im-stat-value">{{ $colonies->count() }}</div></div></div>
                <div class="col-md-3 col-6"><div class="im-stat-card im-stat-mini"><div class="im-stat-label">Extractors</div><div class="im-stat-value">{{ $extractors->count() }}</div></div></div>
                <div class="col-md-3 col-6"><div class="im-stat-card im-stat-mini"><div class="im-stat-label">Expiring &lt;24h</div><div class="im-stat-value {{ $expiring->count() ? 'im-text-danger' : '' }}">{{ $expiring->count() }}</div></div></div>
                <div class="col-md-3 col-6"><div class="im-stat-card im-stat-mini"><div class="im-stat-label">Factory lines</div><div class="im-stat-value">{{ $factories->count() }}</div></div></div>
            </div>

            <div class="card card-dark">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item"><a class="nav-link {{ $expiring->count() ? 'active' : '' }}" data-toggle="tab" href="#pi-attention"><i class="fas fa-bell mr-1"></i> Attention @if($expiring->count())<span class="im-badge im-status-cancelled ml-1">{{ $expiring->count() }}</span>@endif</a></li>
                        <li class="nav-item"><a class="nav-link {{ $expiring->count() ? '' : 'active' }}" data-toggle="tab" href="#pi-extractors"><i class="fas fa-droplet mr-1"></i> Extractors</a></li>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#pi-factories"><i class="fas fa-industry mr-1"></i> Factories</a></li>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#pi-colonies"><i class="fas fa-globe mr-1"></i> Colonies</a></li>
                        <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#pi-storage"><i class="fas fa-box mr-1"></i> Storage</a></li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">

                        {{-- Attention --}}
                        <div class="tab-pane fade {{ $expiring->count() ? 'show active' : '' }}" id="pi-attention">
                            @if($expiring->isEmpty())
                                <div class="im-empty-state"><i class="fas fa-circle-check"></i><p>No extractors expiring in the next 24 hours. Nice.</p></div>
                            @else
                                <p class="im-text-muted">Extractors expiring within 24 hours. An expired extractor stops producing until you reset its program.</p>
                                @include('industry-manager::pi._extractor_table', ['rows' => $expiring])
                            @endif
                        </div>

                        {{-- Extractors --}}
                        <div class="tab-pane fade {{ $expiring->count() ? '' : 'show active' }}" id="pi-extractors">
                            @if($extractors->isEmpty())
                                <div class="im-empty-state"><i class="fas fa-droplet"></i><p>No active extractors.</p></div>
                            @else
                                @include('industry-manager::pi._extractor_table', ['rows' => $extractors])
                            @endif
                        </div>

                        {{-- Factories --}}
                        <div class="tab-pane fade" id="pi-factories">
                            @if($factories->isEmpty())
                                <div class="im-empty-state"><i class="fas fa-industry"></i><p>No factories.</p></div>
                            @else
                                <table class="table im-table">
                                    <thead><tr><th>Character</th><th>Planet</th><th>Producing</th><th class="text-center">Lines</th><th class="text-center">Cycle</th></tr></thead>
                                    <tbody>
                                        @foreach($factories as $f)
                                            <tr>
                                                <td class="im-text-muted">{{ $f['character_name'] }}</td>
                                                <td>{{ $f['planet_name'] }}</td>
                                                <td>
                                                    <span class="im-bp-name">{{ $f['output_name'] ?? $f['schematic_name'] }}</span>
                                                    @if($f['output_qty'])<span class="im-text-muted"> &times;{{ $f['output_qty'] }}/cycle</span>@endif
                                                </td>
                                                <td class="text-center"><span class="im-badge im-badge-build">{{ $f['factory_count'] }}</span></td>
                                                <td class="text-center im-text-muted">{{ $f['cycle_time'] ? \IndustryManager\Helpers\Format::duration($f['cycle_time']) : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>

                        {{-- Colonies --}}
                        <div class="tab-pane fade" id="pi-colonies">
                            <table class="table im-table">
                                <thead><tr><th>Character</th><th>Planet</th><th>Type</th><th>System</th><th class="text-center">Upgrade</th><th class="text-center">Pins</th></tr></thead>
                                <tbody>
                                    @foreach($colonies as $c)
                                        <tr>
                                            <td class="im-text-muted">{{ $c['character_name'] }}</td>
                                            <td><span class="im-bp-name">{{ $c['planet_name'] }}</span></td>
                                            <td>{{ $c['planet_type'] }}</td>
                                            <td>{{ $c['system_name'] }}@if($c['security'] !== null)<span class="im-text-muted"> ({{ number_format($c['security'],1) }})</span>@endif</td>
                                            <td class="text-center">{{ $c['upgrade_level'] }}</td>
                                            <td class="text-center">{{ $c['num_pins'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Storage --}}
                        <div class="tab-pane fade" id="pi-storage">
                            @if($storage->isEmpty())
                                <div class="im-empty-state"><i class="fas fa-box"></i><p>No stored materials.</p></div>
                            @else
                                <table class="table im-table">
                                    <thead><tr><th>Character</th><th>Planet</th><th>Material</th><th class="text-right">Amount</th></tr></thead>
                                    <tbody>
                                        @foreach($storage as $s)
                                            <tr>
                                                <td class="im-text-muted">{{ $s['character_name'] }}</td>
                                                <td>{{ $s['planet_name'] }}</td>
                                                <td>{{ $s['type_name'] }}</td>
                                                <td class="text-right">{{ number_format($s['amount']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>

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
        function parseEve(str) {
            if (!str) return null;
            var iso = str.replace(' ', 'T');
            if (!/[zZ]|[+-]\d\d:?\d\d$/.test(iso)) iso += 'Z';
            var d = new Date(iso);
            return isNaN(d.getTime()) ? null : d;
        }
        function fmt(ms) {
            if (ms <= 0) return null;
            var s = Math.floor(ms / 1000);
            var d = Math.floor(s / 86400); s -= d * 86400;
            var h = Math.floor(s / 3600); s -= h * 3600;
            var m = Math.floor(s / 60);
            var p = [];
            if (d) p.push(d + 'd');
            if (h) p.push(h + 'h');
            if (!d) p.push(m + 'm');
            return p.join(' ');
        }
        function tick() {
            var now = Date.now();
            document.querySelectorAll('.im-pi-expiry').forEach(function (el) {
                var end = parseEve(el.getAttribute('data-expiry'));
                if (!end) { el.textContent = '—'; return; }
                var rem = fmt(end.getTime() - now);
                el.innerHTML = rem
                    ? '<span class="im-eta-remaining">' + rem + '</span> <span class="im-eta-local">' + end.toLocaleString() + '</span>'
                    : '<span class="im-eta-expired">Expired</span> <span class="im-eta-local">' + end.toLocaleString() + '</span>';
            });
            document.querySelectorAll('.im-pi-progress').forEach(function (el) {
                var start = parseEve(el.getAttribute('data-install'));
                var end = parseEve(el.getAttribute('data-expiry'));
                if (!start || !end || end <= start) { return; }
                var pct = Math.max(0, Math.min(100, Math.round(((now - start) / (end - start)) * 100)));
                var cls = pct < 60 ? 'ok' : (pct < 85 ? 'warn' : 'danger');
                var bar = el.querySelector('.im-pi-progress-bar');
                if (bar) { bar.style.width = pct + '%'; bar.className = 'im-pi-progress-bar ' + cls; bar.textContent = pct + '%'; }
            });
        }
        tick();
        setInterval(tick, 30000);
    })();
</script>
@endpush
