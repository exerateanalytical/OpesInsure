{{-- SSR §26 timeline: timestamp, actor, role, event, result, reason, channel, related document. --}}
<div class="oi-timeline" data-testid="timeline">
    @forelse ($entries as $e)
        <div style="display:grid;grid-template-columns:10rem 1fr;gap:.25rem 1rem;padding:.625rem 0;border-bottom:1px solid var(--gray-200, #DCE3E8)">
            <div style="font-size:.8125rem;color:var(--gray-600, #566776);font-variant-numeric:tabular-nums">{{ \Illuminate\Support\Str::of($e['at'])->substr(0, 19) }}</div>
            <div>
                <div style="font-weight:600">{{ $e['event'] }}
                    @if ($e['result']) <x-filament::badge size="sm" :color="\App\Application\WebExperiences\RecordSummary::toneFor($e['result'])">{{ $e['result'] }}</x-filament::badge> @endif
                </div>
                <div style="font-size:.8125rem;color:var(--gray-700, #3C4C5B)">
                    {{ $e['actor'] ?? '—' }}@if ($e['role']) · {{ $e['role'] }}@endif
                    @if ($e['channel']) · {{ __('web_experience.timeline.channel') }}: {{ $e['channel'] }}@endif
                    @if ($e['reason']) · {{ __('web_experience.timeline.reason') }}: {{ $e['reason'] }}@endif
                    @if ($e['document_id']) · {{ __('web_experience.timeline.document') }}: {{ $e['document_id'] }}@endif
                </div>
            </div>
        </div>
    @empty
        <p style="color:var(--gray-600, #566776)">{{ __('web_experience.timeline.empty') }}</p>
    @endforelse
</div>
