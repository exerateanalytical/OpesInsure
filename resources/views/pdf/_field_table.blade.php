{{-- Table field of a canonical template (TEMPLATE_CONTENT_CONTRACT §2, format "table"): one row per recorded item. --}}
<div style="margin-top:6px;page-break-inside:auto">
  <div style="font-weight:bold;font-size:9.5px;margin-bottom:2px">{{ $t['label'] }}</div>
  @if($t['recorded'])
    <table class="grid">
      <tr>@foreach($t['columns'] as $c)<td class="k" style="width:auto"><strong>{{ $c }}</strong></td>@endforeach</tr>
      @foreach($t['rows'] as $cells)<tr style="page-break-inside:avoid">@foreach($cells as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach
    </table>
  @else
    <div class="small muted">{{ $t['value'] }}</div>
  @endif
</div>
