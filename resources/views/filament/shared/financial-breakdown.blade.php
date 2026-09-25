{{-- Canonical handoff "Insurance and financial UI rules": gross premium, platform fee, processing fee, commission and carrier
     settlement are distinct labelled values (never merged), amounts in tabular numerals with FCFA kept attached.
     $lines: array<key, ?int minor> with keys from web_experience.breakdown.*; null = not yet calculated. $currency, $total (?int).
     Values come from the caller's persisted figures; this view never computes premiums, fees or commission. --}}
@php($currency = $currency ?? 'XAF')
<section class="oi-card" data-testid="financial-breakdown" aria-labelledby="oi-breakdown-{{ $id ?? 'x' }}">
    <h3 id="oi-breakdown-{{ $id ?? 'x' }}" style="margin:0 0 .5rem;font-size:1rem">{{ __('web_experience.breakdown.heading') }}</h3>
    <dl class="oi-breakdown">
        @foreach ($lines as $key => $minor)
            <div class="oi-breakdown__row" data-line="{{ $key }}">
                <dt>{{ __('web_experience.breakdown.'.$key) }}</dt>
                <dd class="oi-money">{{ $minor === null ? __('web_experience.breakdown.pending') : \App\Application\WebExperiences\Money::display((int) $minor, $currency) }}</dd>
            </div>
        @endforeach
        @if (isset($total))
            <div class="oi-breakdown__row oi-breakdown__row--total" data-line="total">
                <dt>{{ __('web_experience.breakdown.total') }}</dt>
                <dd class="oi-money">{{ \App\Application\WebExperiences\Money::display((int) $total, $currency) }}</dd>
            </div>
        @endif
    </dl>
</section>
