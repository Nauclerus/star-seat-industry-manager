{{-- Recursive production-tree partial.
     Expects: $node (a blueprint node with ['materials' => [...]]) and $depth (int).
     Each material is either a leaf (acquire as-is) or has ['children' => sub-node].
     Buildable materials with children render as a <details> so the branch is
     expand/collapse with zero JS. Indentation is driven by the --im-depth CSS var. --}}
@foreach($node['materials'] as $mat)
    @if(!empty($mat['children']))
        <details class="im-tree-branch" open>
            <summary class="im-tree-row im-tree-buildable" style="--im-depth: {{ $depth }};">
                <i class="fas fa-caret-right im-tree-caret"></i>
                <span class="im-tree-name">{{ $mat['name'] }}</span>
                <span class="im-badge im-badge-build" title="Buildable — expanded below">build</span>
                @if(!empty($mat['sub_runs']))
                    <span class="im-tree-subruns">{{ number_format($mat['sub_runs']) }} run(s)</span>
                @endif
                <span class="im-tree-qty">&times;{{ number_format($mat['quantity']) }}</span>
            </summary>
            <div class="im-tree-children">
                @include('industry-manager::calculator._tree_node', ['node' => $mat['children'], 'depth' => $depth + 1])
            </div>
        </details>
    @else
        <div class="im-tree-row im-tree-leaf" style="--im-depth: {{ $depth }};">
            <i class="fas fa-circle im-tree-bullet"></i>
            <span class="im-tree-name">{{ $mat['name'] }}</span>
            @if($mat['buildable'])
                <span class="im-badge im-badge-buildable-leaf" title="Buildable, but shown as a leaf (depth limit reached) — acquire or expand separately">buildable</span>
            @endif
            <span class="im-tree-qty">&times;{{ number_format($mat['quantity']) }}</span>
        </div>
    @endif
@endforeach
