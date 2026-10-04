@extends('web::layouts.grids.12')

@section('title', 'Jobs — Industry Manager')
@section('page_header', 'Active Industry Jobs')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=4">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        <div class="row">
            <div class="col-md-3 col-6">
                <div class="im-stat-card im-stat-mini">
                    <div class="im-stat-label">In Progress</div>
                    <div class="im-stat-value">{{ number_format($counts['active']) }}</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="im-stat-card im-stat-mini">
                    <div class="im-stat-label">Ready</div>
                    <div class="im-stat-value im-text-ready">{{ number_format($counts['ready']) }}</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="im-stat-card im-stat-mini">
                    <div class="im-stat-label">Paused</div>
                    <div class="im-stat-value">{{ number_format($counts['paused']) }}</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="im-stat-card im-stat-mini">
                    <div class="im-stat-label">Recent Delivered</div>
                    <div class="im-stat-value im-text-muted">{{ number_format($counts['delivered']) }}</div>
                </div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-cogs mr-2"></i> Industry Jobs</h3>
            </div>
            <div class="card-body">
                @if($jobs->isEmpty())
                    <div class="im-empty-state">
                        <i class="fas fa-cogs"></i>
                        <p>No industry jobs found for your characters or corporations.</p>
                        <p class="im-text-muted">SeAT syncs jobs periodically. If you just started one, check back after the next sync.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table id="im-jobs-table" class="table im-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Activity</th>
                                    <th>Blueprint → Product</th>
                                    <th class="text-center">Runs</th>
                                    <th>Installer</th>
                                    <th>Facility</th>
                                    <th class="text-center">Status</th>
                                    <th>ETA</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($jobs as $job)
                                    <tr>
                                        <td><i class="{{ $job['activity_icon'] }} mr-1 im-text-muted"></i> {{ $job['activity_name'] }}</td>
                                        <td>
                                            <span class="im-bp-name">{{ $job['blueprint_name'] }}</span>
                                            @if($job['product_name'])
                                                <span class="im-text-muted"> &rarr; {{ $job['product_name'] }}</span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ number_format($job['runs']) }}</td>
                                        <td class="im-text-muted">{{ $job['installer_name'] }}</td>
                                        <td class="im-text-muted">{{ $job['facility_name'] ?? '—' }}</td>
                                        <td class="text-center">
                                            <span class="im-badge im-status-{{ $job['status'] }}">{{ ucfirst($job['status']) }}</span>
                                        </td>
                                        <td data-order="{{ $job['end_date'] }}">
                                            <span class="im-eta" data-end="{{ $job['end_date'] }}" data-status="{{ $job['status'] }}">{{ $job['end_date'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="im-text-muted mt-2"><i class="fas fa-circle-info mr-1"></i> ETAs convert EVE (UTC) time to your local time. Job data reflects SeAT's last sync.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@stop

@push('javascript')
<script>
    (function () {
        function fmtRemaining(ms) {
            if (ms <= 0) return null;
            var s = Math.floor(ms / 1000);
            var d = Math.floor(s / 86400); s -= d * 86400;
            var h = Math.floor(s / 3600); s -= h * 3600;
            var m = Math.floor(s / 60);
            var parts = [];
            if (d) parts.push(d + 'd');
            if (h) parts.push(h + 'h');
            if (!d) parts.push(m + 'm');
            return parts.join(' ');
        }

        function parseEve(str) {
            if (!str) return null;
            // SeAT stores UTC 'YYYY-MM-DD HH:MM:SS'; treat as UTC.
            var iso = str.replace(' ', 'T');
            if (!/[zZ]|[+-]\d\d:?\d\d$/.test(iso)) iso += 'Z';
            var d = new Date(iso);
            return isNaN(d.getTime()) ? null : d;
        }

        function tick() {
            var now = Date.now();
            document.querySelectorAll('.im-eta').forEach(function (el) {
                var end = parseEve(el.getAttribute('data-end'));
                var status = el.getAttribute('data-status');
                if (!end) { el.textContent = '—'; return; }
                var local = end.toLocaleString();
                if (status === 'active') {
                    var remaining = fmtRemaining(end.getTime() - now);
                    if (remaining) {
                        el.innerHTML = '<span class="im-eta-remaining">' + remaining + '</span> <span class="im-eta-local">' + local + '</span>';
                    } else {
                        el.innerHTML = '<span class="im-eta-done">Complete</span> <span class="im-eta-local">' + local + '</span>';
                    }
                } else {
                    el.innerHTML = '<span class="im-eta-local">' + local + '</span>';
                }
            });
        }

        tick();
        setInterval(tick, 60000);

        $(function () {
            if ($.fn.DataTable && document.getElementById('im-jobs-table')) {
                $('#im-jobs-table').DataTable({ order: [[6, 'asc']], pageLength: 25 });
            }
        });
    })();
</script>
@endpush
