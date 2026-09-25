{{-- SSR §30: explicit known-failure banners (never a generic error). $states: list<App\Application\WebExperiences\FailureState> --}}
@foreach ($states as $state)
    <div role="alert" data-failure="{{ $state->value }}" style="margin-top:.75rem;padding:.75rem 1rem;border-radius:.75rem;border:1px solid {{ $state->tone() === 'danger' ? '#E8A9AE' : '#EAC66F' }};background:{{ $state->tone() === 'danger' ? '#FDEDEF' : '#FFF6DD' }};color:{{ $state->tone() === 'danger' ? '#98272E' : '#764B00' }}">
        <strong>{{ $state->title() }}</strong>
        <div>{{ $state->message() }}</div>
    </div>
@endforeach
