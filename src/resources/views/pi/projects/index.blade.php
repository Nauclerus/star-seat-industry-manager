@extends('web::layouts.grids.12')

@section('title', 'PI Projects — Industry Manager')
@section('page_header', 'PI Projects')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/industry-manager/css/industry-manager.css') }}?v=5">
@endpush

@section('full')
<div class="industry-manager-wrapper">
    <div class="industry-manager im-pi">

        @if(session('success'))
            <div class="alert im-inline-alert alert-mc-success"><i class="fas fa-circle-check mr-2"></i>{{ session('success') }}</div>
        @endif

        <div class="row">
            <div class="col-lg-5">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-plus mr-2"></i> New Project</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('industry-manager.pi.projects.store') }}">
                            @csrf
                            <div class="form-group">
                                <label>Name</label>
                                <input type="text" name="name" class="form-control" maxlength="120" required placeholder="e.g. Fuel Block Supply Chain">
                            </div>
                            <div class="form-group">
                                <label>Description <span class="im-text-muted">(optional)</span></label>
                                <textarea name="description" class="form-control" rows="2" maxlength="500"></textarea>
                            </div>
                            <button type="submit" class="btn btn-im-primary"><i class="fas fa-plus mr-1"></i> Create</button>
                        </form>
                        <p class="im-text-muted mt-2">A project lets you set planetary production targets and assign your colonies to supply them.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card card-dark">
                    <div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-clipboard-list mr-2"></i> Your Projects</h3></div>
                    <div class="card-body">
                        @if($projects->isEmpty())
                            <div class="im-empty-state"><i class="fas fa-clipboard-list"></i><p>No projects yet. Create one to start planning.</p></div>
                        @else
                            <table class="table im-table">
                                <thead><tr><th>Name</th><th class="text-center">Objectives</th><th class="text-center">Planets</th><th class="text-right">Action</th></tr></thead>
                                <tbody>
                                    @foreach($projects as $p)
                                        <tr>
                                            <td>
                                                <span class="im-bp-name">{{ $p->name }}</span>
                                                @if($p->description)<div class="im-text-muted" style="font-size:0.8rem;">{{ \Illuminate\Support\Str::limit($p->description, 80) }}</div>@endif
                                            </td>
                                            <td class="text-center">{{ $p->objectives_count }}</td>
                                            <td class="text-center">{{ $p->planets_count }}</td>
                                            <td class="text-right">
                                                <a href="{{ route('industry-manager.pi.projects.show', ['id' => $p->id]) }}" class="btn btn-sm btn-im-primary"><i class="fas fa-eye mr-1"></i> Open</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@stop
