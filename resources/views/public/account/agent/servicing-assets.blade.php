{{-- Agent servicing pages (AGT-037..056, launch 2026-10-02): the agent area assets plus launch_agent_b copy and agent-servicing.js.
     Outside pages/ so it is not routable. --}}
@include('public.account.agent.assets')
@push('scripts')
<script>window.AGENT_B_T = {!! json_encode(__('launch_agent_b.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/agent-servicing.js?v={{ @filemtime(public_path('landing/portal/agent-servicing.js')) }}"></script>
@endpush
