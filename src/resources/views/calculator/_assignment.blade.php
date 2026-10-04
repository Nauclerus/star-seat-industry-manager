{{-- Per-run assignment: which structure and which character this run is planned in.
     Expects: $node (a tree node with ['assignment' => ...]) and $depth (int). --}}
@php($a = $node['assignment'] ?? null)
@if($a)
    <div class="im-tree-assignment" style="--im-depth: {{ $depth }};">
        <span class="im-tree-assign-label">run in</span>
        <span class="im-tree-assign-structure">{{ $a['structure_name'] ?: 'no structure available' }}</span>
        @if(!empty($a['service']))
            <span class="im-text-muted">&middot; {{ $a['service'] }}</span>
        @endif
        <span class="im-text-muted">&middot; {{ $a['character_name'] ?: 'no character' }}</span>
        @if(!empty($node['adjusted_time']))
            <span class="im-text-muted" title="Duration of {{ number_format($node['runs']) }} run(s) in this plan">&middot; {{ \IndustryManager\Helpers\Format::duration($node['adjusted_time'] * $node['runs']) }}</span>
        @endif

        @php($sb = $a['structure_bonus'] ?? ['material' => 1.0, 'cost' => 1.0, 'time' => 1.0])
        @if($sb['material'] < 1 || $sb['cost'] < 1 || $sb['time'] < 1)
            <span class="im-badge im-badge-structure-bonus" title="Bonus this structure type grants itself">
                @if($sb['material'] < 1)-{{ round((1 - $sb['material']) * 100, 1) }}% mat @endif
                @if($sb['cost'] < 1)-{{ round((1 - $sb['cost']) * 100, 1) }}% cost @endif
                @if($sb['time'] < 1)-{{ round((1 - $sb['time']) * 100, 1) }}% time @endif
            </span>
        @endif

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
