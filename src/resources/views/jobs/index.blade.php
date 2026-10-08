@extends('web::layouts.grids.12')

@php use IndustryManager\Helpers\JobScope; @endphp

@section('title', 'Jobs — Industry Manager')
@section('page_header', 'Active Industry Jobs')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=8">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager">

        {{-- Scope tabs: the in-game industry window filter --}}
        <div class="im-scope-tabs">
            @foreach(JobScope::all() as $scope)
                <a href="{{ route('industry-manager.jobs', $filter->parameters($scope)) }}"
                   class="im-scope-tab {{ $filter->scope === $scope ? 'active' : '' }}">
                    <i class="{{ JobScope::icon($scope) }} mr-1"></i>
                    {{ JobScope::label($scope) }}
                    <span class="im-scope-count">{{ number_format($scopeCounts[$scope] ?? 0) }}</span>
                </a>
            @endforeach
        </div>
        <p class="im-scope-note">
            <i class="fas fa-circle-info mr-1"></i>
            {{ JobScope::description($filter->scope) }}
        </p>

        {{-- Stat cards for the current selection --}}
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

        {{-- Filters --}}
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-cogs mr-2"></i> Industry Jobs</h3>
                <div class="im-card-tools">
                    <span class="im-text-muted">{{ number_format($counts['total']) }} shown</span>
                    @if($filter->hasRestrictions())
                        <a href="{{ route('industry-manager.jobs', ['scope' => $filter->scope]) }}" class="im-pill">
                            <i class="fas fa-xmark mr-1"></i> Clear filters
                        </a>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('industry-manager.jobs') }}" class="im-filter-bar">
                    <input type="hidden" name="scope" value="{{ $filter->scope }}">

                    <div class="form-row">
                        <div class="form-group col-md-3 col-6">
                            <label for="im-filter-activity">Activity</label>
                            <select id="im-filter-activity" name="activity" class="form-control" onchange="this.form.submit()">
                                <option value="">All activities</option>
                                @foreach($facets['activities'] as $a)
                                    <option value="{{ $a['id'] }}" {{ $filter->activity === $a['id'] ? 'selected' : '' }}>
                                        {{ $a['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group col-md-2 col-6">
                            <label for="im-filter-status">Status</label>
                            <select id="im-filter-status" name="status" class="form-control" onchange="this.form.submit()">
                                <option value="">Any status</option>
                                @foreach($facets['statuses'] as $s)
                                    <option value="{{ $s['id'] }}" {{ $filter->status === $s['id'] ? 'selected' : '' }}>
                                        {{ $s['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group col-md-3 col-6">
                            <label for="im-filter-structure">Structure</label>
                            <select id="im-filter-structure" name="structure" class="form-control" onchange="this.form.submit()">
                                <option value="">All structures</option>
                                @foreach($facets['structures'] as $st)
                                    <option value="{{ $st['id'] }}" {{ $filter->structure === $st['id'] ? 'selected' : '' }}>
                                        {{ $st['name'] }}{{ $st['system'] ? ' — ' . $st['system'] : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group col-md-2 col-6">
                            <label for="im-filter-installer">Installed by</label>
                            <select id="im-filter-installer" name="installer" class="form-control" onchange="this.form.submit()">
                                <option value="">Anyone</option>
                                @foreach($facets['installers'] as $i)
                                    <option value="{{ $i['id'] }}" {{ $filter->installer === $i['id'] ? 'selected' : '' }}>
                                        {{ $i['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group col-md-2">
                            <label for="im-filter-q">Search</label>
                            <input id="im-filter-q" type="search" name="q" class="form-control"
                                   value="{{ $filter->search }}" placeholder="blueprint, product, system">
                        </div>
                    </div>

                    <noscript><button type="submit" class="btn btn-im-primary">Apply filters</button></noscript>
                </form>

                @if($jobs->isEmpty())
                    <div class="im-empty-state">
                        <i class="fas fa-cogs"></i>
                        @if($filter->hasRestrictions())
                            <p>No jobs match the current filters.</p>
                            <p class="im-text-muted">
                                <a href="{{ route('industry-manager.jobs', ['scope' => $filter->scope]) }}">Clear the filters</a>
                                or try another scope above.
                            </p>
                        @else
                            <p>No industry jobs found for your characters or corporations.</p>
                            <p class="im-text-muted">If you just started a job, check back after the next sync.</p>
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table id="im-jobs-table" class="table im-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Activity</th>
                                    <th>Blueprint &rarr; Product</th>
                                    <th class="text-center">Runs</th>
                                    <th>Installed by</th>
                                    <th>Structure</th>
                                    <th class="text-center">Belongs to</th>
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
                                        <td class="im-text-muted">
                                            {{ $job['facility_name'] ?? '—' }}
                                            @if($job['system_name'])
                                                <div class="im-subtle">{{ $job['system_name'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @foreach($job['scopes'] as $scope)
                                                <span class="im-badge im-scope-badge im-scope-{{ $scope }}">{{ JobScope::label($scope) }}</span>
                                            @endforeach
                                        </td>
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
                    <p class="im-text-muted mt-2"><i class="fas fa-circle-info mr-1"></i> ETAs are shown in your local time.</p>
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
                $('#im-jobs-table').DataTable({ order: [[7, 'asc']], pageLength: 25 });
            }
        });
    })();
</script>
@endpush
