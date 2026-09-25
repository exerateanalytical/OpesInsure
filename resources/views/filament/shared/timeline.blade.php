{{-- SSR §26 timeline: timestamp, actor, role, event, result, reason, channel, related document. Ordered list for assistive tech. --}}
<div class="oi-timeline" data-testid="timeline">
    @if ($entries === [])
        <p class="oi-empty">{{ __('web_experience.timeline.empty') }}</p>
    @else
        <ol style="list-style:none;margin:0;padding:0" aria-label="{{ __('web_experience.timeline.heading') }}">
            @foreach ($entries as $e)
                <li class="oi-timeline__item">
                    <time class="oi-timeline__when" datetime="{{ $e['at'] }}">{{ \Illuminate\Support\Str::of($e['at'])->substr(0, 16)->replace('T', ' ') }}</time>
                    <div>
                        <div class="oi-timeline__event">{{ $e['event'] }}
                            @if ($e['result']) @include('filament.shared.status-badge', ['status' => $e['result']]) @endif
                        </div>
                        <div class="oi-timeline__meta">
                            <span class="sr-only">{{ __('web_experience.timeline.actor') }}:</span>{{ $e['actor'] ?? __('web_experience.timeline.system') }}@if ($e['role']) · {{ $e['role'] }}@endif
                            @if ($e['channel']) · {{ __('web_experience.timeline.channel') }}: {{ $e['channel'] }}@endif
                            @if ($e['reason']) · {{ __('web_experience.timeline.reason') }}: {{ $e['reason'] }}@endif
                            @if ($e['document_id']) · {{ __('web_experience.timeline.document') }}: <span class="oi-num">{{ $e['document_id'] }}</span>@endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
