{{-- /account/buy — "Create a New Quote" (design: compare_buy_flow/14_stage.png).
     Product/line -> risk questions from the line's risk schema -> POST /quotes -> POST /quotes/{id}/rate -> /account/quotes/{id}.
     Accepts ?line=motor and ?product=CODE (public site "Select Plan"/"Get Quote"). --}}
@extends('public.account.layout', ['title' => __('account_buy.buy_t'), 'lede' => __('account_buy.buy_d'), 'crumbs' => [[__('account_buy.quotes'), '/account/quotes'], [__('account_buy.buy_t'), null]], 'active' => 'quotes'])
@include('public.account.buy.assets')
@section('content')
<div class="buy" data-page-body>
  <div data-steps></div>
  <div class="agrid main-side buy-grid">
    <div class="buy-main">
      <section class="acard" data-customer-card hidden>
        <h2>{{ __('account_buy.customer_h') }}</h2>
        <p class="sub">{{ __('account_buy.customer_s') }}</p>
        <label class="afield-s"><span>{{ __('account_buy.customer_h') }} <i>*</i></span><select data-customer></select></label>
      </section>
      <section class="acard">
        <div class="acard-h"><span>{{ __('account_buy.product_h') }}</span>
          <div class="bbar" data-assets hidden><button type="button" class="dbtn dbtn-outline sm" data-assets-btn>@include('public.partials.i', ['n' => 'motor']){{ __('account_buy.js.from_vehicles') }}</button></div>
        </div>
        <p class="sub">{{ __('account_buy.product_s') }}</p>
        <div data-lines></div>
        <div data-asset-pick hidden></div>
        <p class="bnote" data-product-note hidden></p>
      </section>
      <div class="bform" data-form></div>
    </div>
    <aside class="buy-side">
      <div data-summary></div>
      <section class="acard bnext">
        <h2>@include('public.partials.i', ['n' => 'help']){{ __('account_buy.next_h') }}</h2>
        <ul>@foreach(__('account_buy.next') as $line)<li>{{ $line }}</li>@endforeach</ul>
        <div class="bactions">
          <button type="button" class="dbtn dbtn-outline" data-save>@include('public.partials.i', ['n' => 'doc']){{ __('account_buy.save_later') }}</button>
          <button type="button" class="dbtn dbtn-primary" data-go>{{ __('account_buy.to_compare') }} @include('public.partials.i', ['n' => 'arrow'])</button>
        </div>
      </section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var B = Buy, T = B.T, h = Opes.h, $ = Opes.$;
  var params = ctx.params, line = String(params.get('line') || '').toUpperCase(), productCode = params.get('product') || '';
  var sc = null, fm = null, assetId = null, prefill = {}, product = null, agent = ctx.kind === 'agent';
  var broker = agent && !Opes.can('agent.clients.read') && Opes.can('broker.portal.read');
  var linesBox = $('[data-lines]'), formBox = $('[data-form]'), sumBox = $('[data-summary]'), custSel = $('[data-customer]');
  $('[data-steps]').appendChild(B.steps(0));

  function paint() {
    var facts = fm ? fm.collect().facts : {};
    Opes.clear(sumBox).appendChild(B.summary({ line: line, facts: facts, schema: sc, status: 'DRAFT' }));
  }
  paint();

  if (agent) {
    $('[data-customer-card]').hidden = false;
    custSel.appendChild(h('option', { value: '' }, T.loading_list));
    // Agents pick from their own book; broker staff (no agent.clients.read) from the broker's client list.
    Opes.list(broker ? '/mobile/broker/clients' : '/mobile/agent/clients').then(function (r) {
      Opes.clear(custSel).appendChild(h('option', { value: '' }, T.choose_customer));
      r.items.forEach(function (c) {
        var p = c.party || {};
        custSel.appendChild(h('option', { value: c.id, selected: params.get('customer') === c.id }, [p.display_name || c.full_name || c.display_name || c.id, c.phone_e164].filter(Boolean).join(' · ')));
      });
      if (!r.items.length) Opes.alert(T.agent_no_customers, 'info');
    }).catch(function () { Opes.clear(custSel).appendChild(h('option', { value: '' }, '—')); Opes.alert(T.agent_no_customers, 'info'); });
  }

  function pick(code) {
    line = code; assetId = null;
    Opes.$$('input[name=line]', linesBox).forEach(function (i) { i.checked = i.value === code; });
    $('[data-assets]').hidden = code !== 'MOTOR' || agent;
    $('[data-asset-pick]').hidden = true;
    Opes.loading(formBox);
    B.schema(code).then(function (s) {
      sc = s;
      if (!s || !(s.fields || []).length) { fm = null; paint(); return Opes.fail(formBox, { message: T.schema_failed }); }
      fm = B.form(formBox, s, prefill, paint); paint();
    });
  }

  var pLines = Opes.api('/catalogue/lines');
  var pProduct = productCode ? Opes.list('/catalogue/products').then(function (r) { return r.items.filter(function (p) { return p.code === productCode; })[0] || null; }).catch(function () { return null; }) : Promise.resolve(null);
  Opes.loading(linesBox);
  Promise.all([pLines, pProduct]).then(function (res) {
    var lines = (Array.isArray(res[0]) ? res[0] : []).filter(function (l) { return l.status === 'ACTIVE'; });
    product = res[1];
    if (product) {
      line = String(product.line_code).toUpperCase();
      var note = $('[data-product-note]'); note.textContent = T.product_note.split(':p').join(product.name); note.hidden = false;
    }
    if (!lines.length) return Opes.empty(linesBox, T.no_lines);
    Opes.clear(linesBox).appendChild(h('div', { class: 'opt-cards blines', role: 'radiogroup', 'aria-label': @json(__('account_buy.product_h')) }, lines.map(function (l) {
      var inp = h('input', { type: 'radio', name: 'line', value: l.code });
      inp.addEventListener('change', function () { prefill = {}; pick(l.code); });
      return h('label', { class: 'opt-card' }, inp, Opes.icon(B.lineIcon(l.code)), h('span', null, h('b', null, B.tr(l.name) || B.lineName(l.code))));
    })));
    if (line && lines.some(function (l) { return l.code === line; })) pick(line);
  }).catch(function (e) { Opes.fail(linesBox, e); });

  // "Add from My Vehicles": prefill from a saved vehicle (GET /mobile/assets) and link it to the quote.
  $('[data-assets-btn]').addEventListener('click', function () {
    var box = $('[data-asset-pick]'); box.hidden = false; Opes.loading(box);
    Opes.list('/mobile/assets').then(function (r) {
      var cars = r.items.filter(function (a) { return String(a.type).toUpperCase() === 'VEHICLE'; });
      if (!cars.length) return Opes.empty(box, T.no_vehicles, h('a', { class: 'dbtn dbtn-outline sm', href: '/account/vehicles' }, T.from_vehicles));
      Opes.clear(box).appendChild(h('div', { class: 'opt-cards' }, cars.map(function (a) {
        return h('button', { type: 'button', class: 'opt-card', onclick: function () {
          prefill = Object.assign({}, a.facts || {}); delete prefill.zone; delete prefill.usage_type;
          pick('MOTOR'); assetId = a.id; box.hidden = true;
          Opes.alert(T.vehicle_applied.replace(':v', a.display_name || ''), 'ok');
        } }, Opes.icon('motor'), h('span', null, h('b', null, a.display_name || (a.facts && a.facts.registration_number) || a.id)));
      })));
    }).catch(function (e) { Opes.fail(box, e); });
  });

  function submit(rate, btn) {
    Opes.alert('');
    if (!line || !fm) return Opes.alert(T.pick_line);
    var c = fm.collect();
    if (c.missing.length) { var m = {}; c.missing.forEach(function (k) { m[k] = T.required; }); fm.errors(m); return Opes.alert(T.fix_fields); }
    var customerId = agent ? custSel.value : ctx.session.customer_id;
    if (!customerId) return Opes.alert(T.no_customer);
    Opes.busy(btn, true);
    Opes.api('/quotes', { body: { customer_id: customerId, line_code: line, channel: broker ? 'BROKER' : (agent ? 'AGENT' : 'B2C'), risk_facts: c.facts, risk_asset_id: assetId || undefined } })
      .then(function (q) {
        if (!rate) { location.href = '/account/quotes?saved=' + encodeURIComponent(q.id); return; }
        return Opes.api('/quotes/' + q.id + '/rate', { method: 'POST', body: {} }).then(function () {
          location.href = B.qurl(q.id, '', { product: productCode });
        }, function (e) {
          // The quote exists; its page shows the honest state and lets the user retry pricing.
          location.href = B.qurl(q.id, '', { product: productCode, rate_error: (e && e.message) || '1' });
        });
      })
      .catch(function (e) { Opes.busy(btn, false); if (e && e.errors) fm.errors(e.errors); Opes.alert((e && e.message) || Opes.t.error); });
  }
  $('[data-go]').addEventListener('click', function (e) { submit(true, e.currentTarget); });
  $('[data-save]').addEventListener('click', function (e) { submit(false, e.currentTarget); });
});
</script>
@endpush
