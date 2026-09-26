{{-- Agent / broker area assets (outside pages/ so it is not routable): included by customers, customers/show, leads, reports, commissions.
     Reuses desk.css for the shared stat cards / side cards / search / pager, then agent.css for the agent-only pieces. --}}
@push('head')
<link rel="stylesheet" href="/landing/portal/desk.css?v={{ @filemtime(public_path('landing/portal/desk.css')) }}">
<link rel="stylesheet" href="/landing/portal/agent.css?v={{ @filemtime(public_path('landing/portal/agent.css')) }}">
@endpush
@push('scripts')
<script>window.AGENT_T = {!! json_encode(__('account_agent.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/agent.js?v={{ @filemtime(public_path('landing/portal/agent.js')) }}"></script>
@endpush
