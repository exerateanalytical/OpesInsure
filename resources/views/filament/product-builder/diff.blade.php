{{-- REQ-PRD-007 configuration diff against the base version (PRE §78). --}}
<div>
    @if (! $diff['against_version_id'])
        <p style="color:#667">{{ __('product_builder.diff.no_base') }}</p>
    @elseif ($diff['changes'] === [])
        <p style="color:#667">{{ __('product_builder.diff.none') }}</p>
    @else
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead><tr style="text-align:left"><th>{{ __('product_builder.diff.path') }}</th><th>{{ __('product_builder.diff.change') }}</th><th>{{ __('product_builder.diff.from') }}</th><th>{{ __('product_builder.diff.to') }}</th></tr></thead>
            <tbody>
            @foreach ($diff['changes'] as $c)
                <tr style="border-top:1px solid #E5E7EB"><td><code>{{ $c['path'] }}</code></td><td>{{ $c['change'] }}</td>
                    <td>{{ is_scalar($c['from']) || $c['from'] === null ? $c['from'] : json_encode($c['from']) }}</td>
                    <td>{{ is_scalar($c['to']) || $c['to'] === null ? $c['to'] : json_encode($c['to']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
