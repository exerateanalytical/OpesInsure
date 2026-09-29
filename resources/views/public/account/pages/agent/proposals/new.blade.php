{{-- /account/agent/proposals/new[?customer=] — AGT-029 Proposal Builder entry: client → priced quote (GET /mobile/partner/agent/quotes,
     GET /quotes/{id}) → offer, then the shared builder /account/quotes/{id}/review?offer= (accept offer → POST /proposals {quote_offer_id,
     party_id} → disclosures, attestation, submit — all book-scoped). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['builder_t'], 'lede' => $K['builder_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['builder_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <section class="acard" data-page-body>
    <form class="ag-form" data-builder-form>
      <label class="afield-s"><span>{{ $K['js']['client'] }} *</span><select name="customer" required data-customer><option value="">{{ $K['js']['choose_client'] }}</option></select></label>
      <label class="afield-s"><span>{{ $K['js']['b_quote'] }} *</span><select name="quote" required data-quote disabled><option value="">{{ $K['js']['b_pick_quote'] }}</option></select></label>
      <div data-offers></div>
      <button class="dbtn dbtn-primary sm" type="submit" disabled data-go>{{ $K['js']['b_go'] }}</button>
    </form>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var form = $('[data-builder-form]'), cs = $('[data-customer]'), qs = $('[data-quote]'), ob = $('[data-offers]'), go = $('[data-go]'), quotes = [], offer = null;
  var OPEN = ['OFFERED', 'RATED', 'GENERATED', 'SENT', 'VIEWED', 'QUOTED', 'ACCEPTED'];
  Promise.all([A.clients(), A.quotes()]).then(function (r) {
    quotes = r[1].filter(function (q) { return q.offers > 0 && OPEN.indexOf(String(q.status).toUpperCase()) >= 0; });
    r[0].forEach(function (c) { cs.appendChild(h('option', { value: c.id, selected: ctx.params.get('customer') === c.id }, [c.full_name, c.phone_e164].filter(Boolean).join(' · '))); });
    if (cs.value) pickClient();
  }).catch(function (e) { A.fail($('[data-page-body]'), e); });
  cs.addEventListener('change', pickClient);
  qs.addEventListener('change', pickQuote);
  function pickClient() {
    O.clear(qs).appendChild(h('option', { value: '' }, L.t('b_pick_quote'))); O.clear(ob); go.disabled = true;
    var mine = L.byCustomer(quotes, cs.value);
    mine.forEach(function (q) { qs.appendChild(h('option', { value: q.id }, A.line(q.line_code) + ' · ' + A.money(q.best_premium_minor) + ' · ' + O.date(q.created_at))); });
    qs.disabled = !mine.length;
    if (cs.value && !mine.length) ob.appendChild(h('p', { class: 'sub', 'data-no-quotes': '' }, L.t('b_no_quotes'), ' ', L.link(L.t('q_new_quote'), '/account/buy?customer=' + encodeURIComponent(cs.value), 'dbtn-primary')));
  }
  function pickQuote() {
    O.clear(ob); go.disabled = true; offer = null;
    if (!qs.value) return;
    O.loading(ob);
    O.api('/quotes/' + encodeURIComponent(qs.value)).then(function (r) {
      O.clear(ob).appendChild(h('fieldset', { class: 'afield-s' }, h('legend', null, L.t('b_offer')), (r.offers || []).map(function (o, i) {
        var rb = h('input', { type: 'radio', name: 'offer', value: o.id });
        rb.addEventListener('change', function () { offer = o.id; go.disabled = false; });
        if (i === 0) { rb.checked = true; offer = o.id; go.disabled = false; }
        return h('label', { style: 'display:block' }, rb, ' ', h('b', null, ((o.carrier || {}).party || {}).display_name || '—'), ' · ', L.tr((o.product || {}).name) || '', ' · ', A.money(o.total_minor));
      })));
    }).catch(function (e) { A.fail(ob, e); });
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!qs.value || !offer) return;
    location.href = '/account/quotes/' + encodeURIComponent(qs.value) + '/review?offer=' + encodeURIComponent(offer);
  });
});
</script>
@endpush
