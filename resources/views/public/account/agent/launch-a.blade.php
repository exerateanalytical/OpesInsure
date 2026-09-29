{{-- Q3 launch agent screens (AGT-005…034, first half): agent area assets + the launch_agent_a copy + agent-a.js helpers.
     Outside pages/ so it is not routable. --}}
@include('public.account.agent.assets')
@push('scripts')
<script>window.LA_T = {!! json_encode(__('launch_agent_a.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/agent-a.js?v={{ @filemtime(public_path('landing/portal/agent-a.js')) }}"></script>
@endpush
