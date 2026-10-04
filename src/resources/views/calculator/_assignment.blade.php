{{-- Per-run assignment: which structure and which character this run is planned in.
     Expects: $node (a tree node with ['assignment' => ...]) and $depth (int). --}}
@php($a = $node['assignment'] ?? null)
@if($a)
    <div class="im-tree-assignment" style="--im-depth: {{ $depth }};">
        <span class="im-tree-assign-label">run in</span>
        <span class="im-tree-assign-structure">{{ $a['structure_name'] ?: 'no structure available' }}</span>
        <span class="im-text-muted">&middot; {{ $a['character_name'] ?: 'no character' }}</span>

        @if($a['me_bonus'] > 0 || $a['te_bonus'] > 0 || $a['cost_bonus'] > 0)
            <span class="im-badge im-badge-assign">
                @if($a['me_bonus'] > 0)-{{ $a['me_bonus'] }}% ME @endif
                @if($a['te_bonus'] > 0)-{{ $a['te_bonus'] }}% TE @endif
                @if($a['cost_bonus'] > 0)-{{ $a['cost_bonus'] }}% cost @endif
            </span>
        @else
            <span class="im-badge im-badge-assign-none" title="No rig fitted on this structure applies to this job">no rig for this job</span>
        @endif

        @if($a['is_override'])
            <span class="im-badge im-badge-override" title="Assigned by you, not auto-picked">override</span>
        @endif
    </div>
@endif
