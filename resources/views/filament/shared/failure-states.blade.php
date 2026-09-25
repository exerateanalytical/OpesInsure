{{-- SSR §30 + canonical handoff "Required component states": explicit state banners, never a generic error.
     $states: list<App\Application\WebExperiences\FailureState>. Danger/warning are announced (role=alert), others are status. --}}
@foreach ($states as $state)
    <div class="oi-state oi-state--{{ $state->tone() }}" role="{{ in_array($state->tone(), ['danger', 'warning'], true) ? 'alert' : 'status' }}" data-failure="{{ $state->value }}">
        {{ svg($state->icon(), '', ['aria-hidden' => 'true', 'focusable' => 'false']) }}
        <div>
            <p class="oi-state__title">{{ $state->title() }}</p>
            <p class="oi-state__body">{{ $state->message() }}</p>
        </div>
    </div>
@endforeach
