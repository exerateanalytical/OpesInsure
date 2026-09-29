{{-- Q8 underwriting workbench section (UnderwritingWorkbenchQuery shape): key/value pairs, tables, notes. Presentation only. --}}
@php($section = $section ?? [])
<div class="oi-uw-section" data-testid="uw-section-{{ $key ?? 'section' }}" style="display:flex;flex-direction:column;gap:1rem">
    @foreach (($section['notes'] ?? []) as $note)
        <div class="oi-state oi-state--info" role="note"><p class="oi-state__body">{{ $note }}</p></div>
    @endforeach
    @if (! empty($section['pairs']))
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.75rem 1.5rem;margin:0">
            @foreach ($section['pairs'] as [$label, $value])
                <div><dt style="font-size:.75rem;opacity:.7">{{ $label }}</dt><dd style="margin:0;font-weight:600;word-break:break-word">{{ $value }}</dd></div>
            @endforeach
        </dl>
    @endif
    @foreach (($section['tables'] ?? []) as $table)
        <div>
            <h4 style="font-weight:600;margin:0 0 .5rem">{{ $table['title'] }}</h4>
            @if (($table['rows'] ?? []) === [])
                <p class="oi-empty">{{ __('uw_workbench.empty') }}</p>
            @else
                <table class="oi-records">
                    <caption class="sr-only">{{ $table['title'] }}</caption>
                    <thead><tr>@foreach ($table['columns'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($table['rows'] as $row)
                        <tr>
                            @foreach ($table['columns'] as $col => $label)
                                <td data-label="{{ $label }}">
                                    @if (in_array($col, $table['status'] ?? [], true) && ($row[$col] ?? '') !== '')
                                        @include('filament.shared.status-badge', ['status' => (string) $row[$col]])
                                    @elseif ($loop->first && isset($row['id'], $table['links']) && isset($caseUrl))
                                        <a href="{{ $caseUrl($row['id']) }}" class="fi-link" style="color:var(--oi-blue-600);font-weight:600">{{ $row[$col] ?? '—' }}</a>
                                    @else
                                        {{ $row[$col] ?? '—' }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</div>
