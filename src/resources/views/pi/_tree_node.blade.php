{{-- Recursive PI schematic tree node. Expects $node and $depth.
     A node: type_id, name, tier, quantity, leaf(bool), children[]. --}}
@php $tierLabel = \IndustryManager\Helpers\PiTier::shortLabel($node['tier'] ?? null); @endphp
@if(empty($node['leaf']) && !empty($node['children']))
    <details class="im-tree-branch" open>
        <summary class="im-tree-row" style="--im-depth: {{ $depth }};">
            <i class="fas fa-caret-right im-tree-caret"></i>
            <span class="im-tier-badge im-tier-{{ $node['tier'] ?? 'x' }}">{{ $tierLabel }}</span>
            <span class="im-tree-name">{{ $node['name'] }}</span>
            @if(!empty($node['cycles']))<span class="im-tree-subruns">{{ number_format($node['cycles']) }} cycle(s)</span>@endif
            <span class="im-tree-qty">&times;{{ number_format($node['quantity']) }}</span>
        </summary>
        <div class="im-tree-children">
            @foreach($node['children'] as $child)
                @include('industry-manager::pi._tree_node', ['node' => $child, 'depth' => $depth + 1])
            @endforeach
        </div>
    </details>
@else
    <div class="im-tree-row im-tree-leaf" style="--im-depth: {{ $depth }};">
        <i class="fas fa-circle im-tree-bullet"></i>
        <span class="im-tier-badge im-tier-{{ $node['tier'] ?? 'x' }}">{{ $tierLabel }}</span>
        <span class="im-tree-name">{{ $node['name'] }}</span>
        <span class="im-tree-qty">&times;{{ number_format($node['quantity']) }}</span>
    </div>
@endif
