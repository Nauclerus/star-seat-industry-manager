{{-- Shared extractor table. Expects $rows (collection of extractor arrays). --}}
<div class="table-responsive">
    <table class="table im-table">
        <thead>
            <tr>
                <th>Character</th>
                <th>Planet</th>
                <th>Extracting</th>
                <th>Progress</th>
                <th>Expires</th>
                <th class="text-right">Qty / hr</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $e)
                <tr>
                    <td class="im-text-muted">{{ $e['character_name'] }}</td>
                    <td>{{ $e['planet_name'] }}</td>
                    <td><span class="im-bp-name">{{ $e['product_name'] }}</span></td>
                    <td style="min-width: 140px;">
                        <div class="im-pi-progress" data-install="{{ $e['install_time'] }}" data-expiry="{{ $e['expiry_time'] }}">
                            <div class="im-pi-progress-bar">0%</div>
                        </div>
                    </td>
                    <td data-order="{{ $e['expiry_time'] }}">
                        <span class="im-pi-expiry" data-expiry="{{ $e['expiry_time'] }}">{{ $e['expiry_time'] }}</span>
                    </td>
                    <td class="text-right">{{ number_format($e['qty_per_hour']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
