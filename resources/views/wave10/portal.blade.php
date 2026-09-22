<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title }} · OpesInsure</title><link rel="stylesheet" href="{{ asset('css/opesinsure-portals.css') }}"></head>
<body>
<aside class="oi-sidebar"><a class="oi-brand" href="/"><span>O</span> OpesInsure</a><nav aria-label="{{ __('wave10.primary_navigation') }}">@foreach($navigation as $item)<a href="{{ $item['url'] }}" @class(['active'=>$item['active']??false])><span aria-hidden="true">{{ $item['icon'] }}</span>{{ $item['label'] }}</a>@endforeach</nav></aside>
<main><header class="oi-topbar"><div><p>{{ $eyebrow }}</p><h1>{{ $title }}</h1></div><div class="oi-actions"><button type="button" aria-label="{{ __('wave10.notifications') }}">●</button><div class="oi-avatar" aria-label="{{ __('wave10.account') }}">{{ $initials }}</div></div></header>
<section class="oi-content"><div class="oi-kpis">@foreach($metrics as $metric)<article><p>{{ $metric['label'] }}</p><strong>{{ $metric['value'] }}</strong><small>{{ $metric['context'] }}</small></article>@endforeach</div><div class="oi-grid"><section class="oi-panel"><h2>{{ __('wave10.priority_work') }}</h2>{{ $slot }}</section><aside class="oi-panel oi-accent"><h2>{{ __('wave10.next_actions') }}</h2>@foreach($actions as $action)<a href="{{ $action['url'] }}">{{ $action['label'] }} <span>→</span></a>@endforeach</aside></div></section>
</main></body></html>
