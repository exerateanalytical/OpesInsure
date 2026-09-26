// OpesInsure account area: quote & purchase flow (pages/buy, pages/quotes*).
// Same API as the mobile app (InsuranceApi / CatalogueApi / VehiclesApi). Nothing is invented:
// prices, covers and statuses come from the quote, offer, proposal and purchase-status payloads.
(function () {
  var O = window.Opes, h = O.h, T = window.BUY_T || {}, L = O.locale === 'fr' ? 'fr' : 'en';

  // ---------- formatting ----------
  function tr(v) {
    if (v === null || v === undefined) return '';
    if (typeof v === 'object') return v[L] || v.en || v.fr || '';
    return String(v);
  }
  function humanize(k) { return String(k || '').replace(/_minor$/, '').replace(/_/g, ' ').toLowerCase().replace(/^./, function (x) { return x.toUpperCase(); }); }
  function minor(v) { return v === null || v === undefined || v === '' ? '—' : O.money(v, { minor: true }); }
  function carrierName(o) {
    var c = (o && o.carrier) || {};
    return c.trade_name || c.short_name || (c.party && c.party.display_name) || c.legal_name || (o && o.carrier_name) || '—';
  }
  function productName(o) { return (o && o.product && o.product.name) || (o && o.product_name) || ''; }
  function initials(n) {
    var w = String(n || '').replace(/\b(SARL|SA|Assurances?|Insurance|Cameroun|Cameroon|au)\b/gi, '').trim().split(/[\s&\-\/]+/).filter(Boolean);
    if (!w.length) w = [String(n || '?')];
    return (w.length > 1 ? w[0].charAt(0) + w[1].charAt(0) : w[0].slice(0, 2)).toUpperCase();
  }
  function tone(n) { var x = 0; n = String(n || ''); for (var i = 0; i < n.length; i++) x = (x * 31 + n.charCodeAt(i)) >>> 0; return x % 5; }
  /** Insurer mark (typographic badge, same look as the public site's .pmark). */
  function mark(o, name) {
    var n = name || carrierName(o), p = name ? '' : productName(o);
    return h('span', { class: 'pmark bmark t' + tone(n) }, h('i', { class: 'mono', 'aria-hidden': 'true' }, initials(n)),
      h('span', { class: 'bm-t' }, h('b', null, n), p ? h('small', null, p) : null));
  }
  var LINE_ICON = { MOTOR: 'motor', HEALTH: 'health', TRAVEL: 'travel', HOME: 'home', BUSINESS: 'business', LIFE: 'life', ACCIDENT: 'accident' };
  function lineIcon(code) { return LINE_ICON[String(code || '').toUpperCase()] || 'shield'; }
  function lineName(code) { return (T.lines || {})[String(code || '').toUpperCase()] || humanize(code); }

  /** Flow stepper: 0 details, 1 coverage, 2 compare, 3 customize, 4 review & payment, 5 confirmation. */
  function steps(cur) {
    var el = O.stepper(T.steps || [], cur);
    el.classList.add('buy-steps'); el.style.setProperty('--n', (T.steps || []).length);
    return el;
  }
  function qurl(id, sub, query) {
    var u = '/account/quotes/' + encodeURIComponent(id) + (sub ? '/' + sub : '');
    var q = new URLSearchParams();
    Object.keys(query || {}).forEach(function (k) { if (query[k]) q.set(k, query[k]); });
    var s = q.toString();
    return u + (s ? '?' + s : '');
  }

  // ---------- risk schema ----------
  var schemas = {};
  function schema(line) {
    line = String(line || '').toUpperCase();
    if (!line) return Promise.resolve(null);
    if (!schemas[line]) schemas[line] = O.api('/mobile/catalogue/lines/' + encodeURIComponent(line) + '/risk-schema').catch(function () { return null; });
    return schemas[line];
  }
  function fieldOf(sc, key) { return (sc && (sc.fields || []).filter(function (f) { return f.key === key; })[0]) || null; }
  function fLabel(f) { return f ? (f['label_' + L] || f.label || humanize(f.key)) : ''; }
  function optLabel(o) { return tr(o['label_' + L] || o.label) || String(o.value); }
  function factLabel(sc, key) { var f = fieldOf(sc, key); return f ? fLabel(f) : ((T.facts || {})[key] || humanize(key)); }
  function factValue(sc, key, v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? T.yes : T.no;
    if (Array.isArray(v)) return v.filter(function (x) { return typeof x !== 'object'; }).map(function (x) { return /^[A-Z0-9_]+$/.test(String(x)) ? O.label(x) : x; }).join(', ') || '—';
    if (typeof v === 'object') return null;
    var f = fieldOf(sc, key);
    if (f && f.options) { var op = f.options.filter(function (o) { return String(o.value) === String(v); })[0]; if (op) return optLabel(op); }
    if (/_minor$/.test(key)) return minor(v);
    if (f && f.type === 'money') return minor(v);
    if (key === 'vehicle_value') return O.money(v);
    return /^[A-Z][A-Z0-9_]+$/.test(String(v)) ? O.label(v) : String(v);
  }
  /** Displayable risk facts: plain values only, skipping *_code keys and nested structures. */
  function factRows(sc, facts, max) {
    var out = [];
    Object.keys(facts || {}).forEach(function (k) {
      if (/_code$/.test(k) || /_other$/.test(k) || k === 'usage_type') return;
      var v = factValue(sc, k, facts[k]);
      if (v === null) return;
      out.push([factLabel(sc, k), v]);
    });
    return max ? out.slice(0, max) : out;
  }
  function riskTitle(line, facts) {
    facts = facts || {};
    if (String(line).toUpperCase() === 'MOTOR' && (facts.make || facts.model)) return [facts.make, facts.model, facts.year].filter(Boolean).join(' ');
    return lineName(line);
  }
  function riskSub(line, facts) {
    facts = facts || {};
    return facts.registration_number || facts.destination_country || facts.city || facts.company_name || '';
  }
  function coverType(sc, facts) {
    facts = facts || {};
    var k = ['cover_type', 'cover_package', 'plan_type', 'cover_scope', 'product_type'].filter(function (x) { return facts[x]; })[0];
    return k ? factValue(sc, k, facts[k]) : '';
  }

  // ---------- quote & offers ----------
  function loadQuote(id) {
    return O.api('/quotes/' + encodeURIComponent(id)).then(function (r) { return { quote: (r && r.quote) || r, offers: (r && r.offers) || [] }; });
  }
  function liveOffers(offers) {
    return (offers || []).filter(function (o) { return o.status === 'OFFERED' || o.status === 'ACCEPTED'; })
      .sort(function (a, b) { return (a.comparison_rank || 99) - (b.comparison_rank || 99) || a.total_minor - b.total_minor; });
  }
  function pickOffer(offers, params) {
    var live = liveOffers(offers), id = params && params.get('offer'), code = params && params.get('product');
    return live.filter(function (o) { return o.id === id; })[0]
      || live.filter(function (o) { return o.status === 'ACCEPTED'; })[0]
      || (code ? live.filter(function (o) { return o.product && o.product.code === code; })[0] : null)
      || live[0] || null;
  }
  function covers(o) { return ((o && o.coverage_snapshot) || {}).coverages || []; }
  function exclusions(o) { return ((o && o.coverage_snapshot) || {}).exclusions || []; }
  function coverList(o, withLimits, max) {
    var list = covers(o);
    if (!list.length) return h('p', { class: 'b-muted' }, T.no_covers);
    var shown = max ? list.slice(0, max) : list;
    return h('ul', { class: 'bcov' }, shown.map(function (c) {
      return h('li', null, O.icon('check'), h('span', null, tr(c.name) || humanize(c.code), c.optional ? h('em', null, ' · ' + T.optional) : null),
        withLimits && c.limit_minor ? h('b', null, minor(c.limit_minor)) : null);
    }), max && list.length > max ? h('li', { class: 'more' }, T.more_covers.replace(':n', list.length - max)) : null);
  }
  function priceRows(o) {
    return h('dl', { class: 'kv bprice' },
      h('dt', null, T.base_premium), h('dd', null, minor(o.premium_minor)),
      h('dt', null, T.taxes), h('dd', null, minor(o.tax_minor)),
      h('dt', null, T.fees), h('dd', null, minor(o.fee_minor)));
  }

  /** Right-hand "Quote Summary" card. opts: {line, facts, quote, offer, schema, status, edit} */
  function summary(opts) {
    var facts = opts.facts || (opts.quote && opts.quote.risk_facts) || {};
    var line = opts.line || (opts.quote && opts.quote.line_code);
    var rows = factRows(opts.schema, facts, 7);
    var o = opts.offer;
    return h('section', { class: 'acard bsum', 'aria-label': T.summary },
      h('div', { class: 'acard-h' }, h('span', null, T.summary), opts.status ? O.chip(opts.status) : null,
        opts.edit ? h('a', { class: 'dbtn dbtn-outline sm', href: opts.edit }, O.icon('edit'), T.edit) : null),
      h('div', { class: 'bsum-head' }, h('span', { class: 'bsum-ic' }, O.icon(lineIcon(line))),
        h('div', null, h('b', null, riskTitle(line, facts)), riskSub(line, facts) ? h('small', null, riskSub(line, facts)) : null,
          coverType(opts.schema, facts) ? h('small', null, coverType(opts.schema, facts)) : null)),
      rows.length ? h('dl', { class: 'kv' }, rows.map(function (r) { return [h('dt', null, r[0]), h('dd', null, r[1])]; })) : h('p', { class: 'b-muted' }, T.summary_empty),
      o && opts.showSel !== false ? h('div', { class: 'bsum-sel' }, h('small', null, T.selected_quote), mark(o)) : null,
      h('div', { class: 'bsum-prem' }, h('small', null, o ? T.total_premium : T.estimated_premium),
        h('b', { class: o ? null : 'soft' }, o ? minor(o.total_minor) : T.after_rating), h('p', null, o ? T.valid_until.replace(':d', O.date(o.valid_until)) : T.premium_note)));
  }

  // ---------- risk form engine (driven by /mobile/catalogue/lines/{LINE}/risk-schema) ----------
  var masters = {};
  function masterDomain(domain) {
    if (!masters[domain]) masters[domain] = O.api('/master-data/' + encodeURIComponent(domain)).catch(function () { return null; });
    return masters[domain];
  }
  function apiPath(src) { return String(src || '').replace(/^\/?api\/v1/, '').replace(/^(?!\/)/, '/'); }
  function itemLabel(x) { return tr(x.label) || x.name || x.display_name || x.code || x.id; }
  function itemValue(x) { return x.code || x.value || x.id; }
  function isEmpty(v) { return v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length); }
  function visible(f, vals) {
    var rule = f.visible_when || f.visible_if;
    if (!rule) return true;
    return Object.keys(rule).every(function (k) {
      var want = rule[k], have = vals[k];
      if (Array.isArray(want)) return want.map(String).indexOf(String(have)) >= 0;
      if (typeof want === 'boolean') return have === want;
      return String(have) === String(want);
    });
  }
  function today() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  /**
   * form(root, schema, initial, onChange) -> {collect(), errors(map), values()}
   * collect() -> {facts, missing:[keys]} with money in minor units and hidden fields dropped.
   */
  function form(root, sc, initial, onChange) {
    var vals = {}, wraps = {}, fields = (sc && sc.fields) || [], reloaders = [];
    Object.keys(initial || {}).forEach(function (k) { vals[k] = initial[k]; });
    // Money is held in FCFA in the form, sent in minor units.
    fields.forEach(function (f) { if (f.type === 'money' && typeof vals[f.key] === 'number') vals[f.key] = vals[f.key] / 100; });
    function changed(key) { refresh(); reloaders.forEach(function (r) { r(key); }); if (onChange) onChange(vals); }

    function control(f, get, set, depKey) {
      var id = 'bf-' + (depKey || '') + f.key + '-' + Math.random().toString(36).slice(2, 7);
      var type = String(f.type || '').toLowerCase(), node;
      function select(items, placeholder) {
        var s = h('select', { id: id, name: f.key }, h('option', { value: '' }, placeholder || T.choose));
        items.forEach(function (it) { s.appendChild(h('option', { value: it[0], selected: String(get()) === String(it[0]) }, it[1])); });
        s.addEventListener('change', function () { set(s.value); });
        return s;
      }
      if (f.key === 'cover_type' && f.options && !depKey) {
        return h('div', { class: 'bcover', role: 'radiogroup', 'aria-label': fLabel(f) }, f.options.map(function (o) {
          var inp = h('input', { type: 'radio', name: 'cover_type', value: o.value, checked: String(get()) === String(o.value) });
          inp.addEventListener('change', function () { set(o.value); });
          return h('label', { class: 'opt-card bcover-c' }, inp, h('span', { class: 'bcover-ic' }, O.icon(o.value === 'THIRD_PARTY' ? 'shield' : o.value === 'COMPREHENSIVE' ? 'motor' : 'lock')),
            h('span', null, h('b', null, optLabel(o)), (T.cover_desc || {})[o.value] ? h('small', null, T.cover_desc[o.value]) : null));
        }));
      }
      if (type === 'select' || (f.options && f.options.length && type !== 'multi_select_master')) {
        return select((f.options || []).map(function (o) { return [o.value, optLabel(o)]; }));
      }
      if (/^vehicle_/.test(type) || (typeof f.source === 'string' && f.source)) {
        node = h('select', { id: id, name: f.key, disabled: true }, h('option', { value: '' }, T.loading_list));
        var lastUrl = null;
        var load = function () {
          var ok = true;
          var url = String(f.source || '').replace(/\{(\w+)\}/g, function (_, k) { var v = vals[k]; if (isEmpty(v)) { if (k !== 'year') ok = false; return ''; } return encodeURIComponent(v); });
          if (!ok) { O.clear(node).appendChild(h('option', { value: '' }, T.choose_first.replace(':f', fLabel(fieldOf(sc, f.depends_on)) || ''))); node.disabled = true; wraps[f.key] && (wraps[f.key].dataset.empty = f.required ? '' : '1'); refresh(); return; }
          if (url === lastUrl) return; lastUrl = url;
          node.disabled = true; O.clear(node).appendChild(h('option', { value: '' }, T.loading_list));
          O.api(apiPath(url) + (/_MAKE$/i.test(f.type) ? (url.indexOf('?') < 0 ? '?' : '&') + 'limit=200' : '')).then(function (d) {
            var list = Array.isArray(d) ? d : ((d && (d.data || d.values || d.items)) || []);
            O.clear(node).appendChild(h('option', { value: '' }, T.choose));
            // Prefilled from a saved vehicle (text only, e.g. facts.make = "Toyota"): match it by name.
            if (isEmpty(get()) && f.text_key && vals[f.text_key]) {
              var hit = list.filter(function (x) { return String(itemLabel(x)).toLowerCase() === String(vals[f.text_key]).toLowerCase(); })[0];
              if (hit) { vals[f.key] = itemValue(hit); setTimeout(function () { changed(f.key); }, 0); }
            }
            list.forEach(function (x) { node.appendChild(h('option', { value: itemValue(x), selected: String(get()) === String(itemValue(x)) }, itemLabel(x))); });
            node.disabled = !list.length;
            if (wraps[f.key]) wraps[f.key].dataset.empty = list.length || f.required ? '' : '1';
            if (!list.length && !f.required) set('', true);
            refresh();
          }).catch(function () { O.clear(node).appendChild(h('option', { value: '' }, T.list_failed)); });
        };
        node.addEventListener('change', function () {
          var opt = node.options[node.selectedIndex];
          if (f.text_key) vals[f.text_key] = node.value ? opt.textContent : '';
          set(node.value);
        });
        reloaders.push(function (k) { if (k !== f.key && String(f.source).indexOf('{' + k + '}') >= 0) { set('', true); lastUrl = null; load(); } });
        load();
        return node;
      }
      if (type === 'select_master' || type === 'multi_select_master') {
        var multi = type === 'multi_select_master';
        var box = h('div', { class: 'bmaster' }, h('span', { class: 'b-muted' }, T.loading_list));
        var src = f.source && typeof f.source === 'object' ? f.source : null;
        var getList = f.endpoint
          ? O.api(apiPath(f.endpoint)).then(function (d) { return Array.isArray(d) ? d : ((d && d.data) || []); })
          : src ? masterDomain(src.domain).then(function (d) {
            var l = d && (d.lists || []).filter(function (x) { return x.code === src.list; })[0];
            return l ? l.values || [] : null;
          }) : Promise.resolve(null);
        var draw = function (list) {
          O.clear(box);
          if (!list || !list.length) {
            // Master list unavailable: free text, sent as typed (the server validates it).
            var cur = get();
            var inp = h('input', { id: id, type: 'text', value: Array.isArray(cur) ? cur.join(', ') : (cur || ''), placeholder: multi ? T.comma_list : '' });
            inp.addEventListener('input', function () { set(multi ? inp.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : inp.value); });
            box.appendChild(inp); return;
          }
          var parentKey = src && src.parent, parentVal = parentKey ? vals[parentKey] : (src && src.parent_code);
          var items = list.filter(function (x) { return !parentVal || !x.parent || x.parent === parentVal || x.is_other; });
          if (multi) {
            var chosen = Array.isArray(get()) ? get().slice() : [];
            box.appendChild(h('div', { class: 'bchecks' }, items.map(function (x) {
              var v = itemValue(x), cb = h('input', { type: 'checkbox', value: v, checked: chosen.indexOf(v) >= 0 });
              cb.addEventListener('change', function () { var i = chosen.indexOf(v); if (cb.checked && i < 0) chosen.push(v); if (!cb.checked && i >= 0) chosen.splice(i, 1); set(chosen.slice()); });
              return h('label', null, cb, h('span', null, itemLabel(x)));
            })));
          } else {
            var opts = items.map(function (x) { return [itemValue(x), itemLabel(x)]; });
            if ((f.allow_other || f.other_allowed) && !items.some(function (x) { return itemValue(x) === 'OTHER'; })) opts.push(['OTHER', T.other]);
            var sel = select(opts);
            box.appendChild(sel);
            if (f.allow_other || f.other_allowed) {
              var other = h('input', { type: 'text', placeholder: T.other_describe, value: vals[f.key + '_other'] || '', hidden: get() !== 'OTHER' });
              other.addEventListener('input', function () { vals[f.key + '_other'] = other.value; });
              sel.addEventListener('change', function () { other.hidden = sel.value !== 'OTHER'; });
              box.appendChild(other);
            }
          }
        };
        var cached = null;
        getList.then(function (list) { cached = list; draw(list); }).catch(function () { draw(null); });
        if (src && src.parent) reloaders.push(function (k) { if (k === src.parent && cached) { set(multi ? [] : '', true); draw(cached); } });
        return box;
      }
      if (type === 'boolean') {
        var b = h('select', { id: id, name: f.key }, h('option', { value: '' }, T.choose), h('option', { value: 'true', selected: get() === true }, T.yes), h('option', { value: 'false', selected: get() === false }, T.no));
        b.addEventListener('change', function () { set(b.value === '' ? '' : b.value === 'true'); });
        return b;
      }
      if (type === 'number' || type === 'money') {
        node = h('input', { id: id, name: f.key, type: 'number', inputmode: 'numeric', min: f.min, max: f.max, step: type === 'money' ? '1' : 'any', value: isEmpty(get()) ? '' : get(), placeholder: type === 'money' ? 'FCFA' : '' });
        node.addEventListener('input', function () { set(node.value === '' ? '' : Number(node.value)); });
        return node;
      }
      if (type === 'date') {
        node = h('input', { id: id, name: f.key, type: 'date', value: get() || '', min: f.min === 'today' ? today() : f.min, max: f.max === 'today' ? today() : f.max });
        node.addEventListener('input', function () { set(node.value); });
        return node;
      }
      if (type === 'repeater') return repeater(f, get, set);
      if (type === 'file') return h('p', { class: 'b-muted' }, T.file_later);
      node = h('input', { id: id, name: f.key, type: 'text', value: get() || '', maxlength: f.max_length || 160, placeholder: f.placeholder || '' });
      node.addEventListener('input', function () { set(node.value); });
      return node;
    }

    function repeater(f, get, set) {
      var items = Array.isArray(get()) ? get().map(function (x) { return Object.assign({}, x); }) : [];
      var min = f.min_items || 0, max = f.max_items || 20;
      while (items.length < Math.max(min, 1)) items.push({});
      var box = h('div', { class: 'brep' });
      function draw() {
        O.clear(box);
        items.forEach(function (it, i) {
          var row = h('div', { class: 'brep-row' }, h('div', { class: 'brep-h' }, h('b', null, fLabel(f) + ' ' + (i + 1)),
            items.length > Math.max(min, 1) ? h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { items.splice(i, 1); set(items.slice()); draw(); } }, O.icon('trash'), T.remove) : null));
          var grid = h('div', { class: 'bgrid' });
          (f.item_fields || []).forEach(function (sub) {
            grid.appendChild(h('label', { class: 'afield-s' }, h('span', null, fLabel(sub), sub.required ? h('i', null, ' *') : null),
              control(sub, function () { return it[sub.key]; }, function (v) { it[sub.key] = v; set(items.slice()); }, f.key + i)));
          });
          row.appendChild(grid); box.appendChild(row);
        });
        if (items.length < max) box.appendChild(h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { items.push({}); set(items.slice()); draw(); } }, '+ ' + T.add_item));
      }
      draw(); set(items.slice(), true);
      return box;
    }

    // Group by schema step; a section card per step.
    var stepList = (sc && sc.steps) || [{ key: '_', label: '' }];
    var known = stepList.map(function (s) { return s.key; });
    O.clear(root);
    stepList.forEach(function (st, si) {
      var fs = fields.filter(function (f) { return f.step === st.key || (si === stepList.length - 1 && known.indexOf(f.step) < 0); });
      if (!fs.length) return;
      var grid = h('div', { class: 'bgrid' });
      fs.forEach(function (f) {
        var wide = f.type === 'repeater' || f.type === 'multi_select_master' || f.key === 'cover_type';
        var w = h('div', { class: 'afield-s bf' + (wide ? ' wide' : ''), 'data-key': f.key },
          h('span', null, fLabel(f), f.required ? h('i', null, ' *') : null),
          control(f, function () { return vals[f.key]; }, function (v, quiet) { vals[f.key] = v; if (wraps[f.key]) wraps[f.key].classList.remove('err'); if (!quiet) changed(f.key); }),
          h('small', { class: 'bf-err', hidden: true }));
        wraps[f.key] = w; grid.appendChild(w);
      });
      root.appendChild(h('section', { class: 'acard bstep', 'data-step': st.key },
        h('h2', null, (si + 1) + '. ' + (st['label_' + L] || st.label || humanize(st.key))), grid));
    });
    function refresh() {
      fields.forEach(function (f) { var w = wraps[f.key]; if (w) w.hidden = !visible(f, vals) || w.dataset.empty === '1'; });
      O.$$('.bstep', root).forEach(function (s) { s.hidden = !O.$$('.bf', s).some(function (w) { return !w.hidden; }); });
    }
    refresh();

    return {
      values: function () { return vals; },
      collect: function () {
        var facts = {}, missing = [];
        fields.forEach(function (f) {
          var w = wraps[f.key];
          if (!w || w.hidden) return;
          var v = vals[f.key];
          if (f.required && f.type !== 'file' && isEmpty(v)) { missing.push(f.key); return; }
          if (isEmpty(v)) return;
          if (f.type === 'money') v = Math.round(Number(v) * 100);
          if (f.type === 'repeater') v = (v || []).map(function (it) {
            var out = {};
            (f.item_fields || []).forEach(function (sub) { var x = it[sub.key]; if (isEmpty(x)) return; out[sub.key] = sub.type === 'money' ? Math.round(Number(x) * 100) : x; });
            return out;
          });
          facts[f.key] = v;
          if (f.text_key && vals[f.text_key]) facts[f.text_key] = vals[f.text_key];
          if (vals[f.key + '_other'] && (v === 'OTHER' || (Array.isArray(v) && v.indexOf('OTHER') >= 0))) facts[f.key + '_other'] = vals[f.key + '_other'];
        });
        return { facts: facts, missing: missing };
      },
      /** errors({key: message}) marks fields; returns the first marked wrapper. */
      errors: function (map) {
        var first = null;
        Object.keys(wraps).forEach(function (k) { wraps[k].classList.remove('err'); var e = O.$('.bf-err', wraps[k]); e.hidden = true; });
        Object.keys(map || {}).forEach(function (k) {
          var key = k.replace(/^risk_facts\./, '').split('.')[0], w = wraps[key];
          if (!w) return;
          w.classList.add('err'); var e = O.$('.bf-err', w); e.textContent = map[k]; e.hidden = false;
          if (!first && !w.hidden) first = w;
        });
        if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return first;
      },
    };
  }

  // ---------- purchase ----------
  /** Stable body idempotency key per proposal + attempt + provider + phone (mirrors the mobile store). */
  function payKey(proposalId, provider, phone) {
    var k = 'opes.buy.attempt.' + proposalId, n = 1;
    try { n = Number(sessionStorage.getItem(k)) || 1; } catch (e) {}
    return 'web-' + proposalId + '-' + n + '-' + provider + '-' + String(phone).replace(/\D/g, '');
  }
  function bumpAttempt(proposalId) {
    var k = 'opes.buy.attempt.' + proposalId;
    try { sessionStorage.setItem(k, String((Number(sessionStorage.getItem(k)) || 1) + 1)); } catch (e) {}
  }
  var PAY_FAILED = ['FAILED', 'EXPIRED', 'CANCELLED'];

  window.Buy = { T: T, tr: tr, humanize: humanize, minor: minor, carrierName: carrierName, productName: productName, mark: mark,
    lineIcon: lineIcon, lineName: lineName, steps: steps, qurl: qurl, schema: schema, fieldOf: fieldOf, fLabel: fLabel, optLabel: optLabel,
    factRows: factRows, factValue: factValue, riskTitle: riskTitle, riskSub: riskSub, coverType: coverType, loadQuote: loadQuote,
    liveOffers: liveOffers, pickOffer: pickOffer, covers: covers, exclusions: exclusions, coverList: coverList, priceRows: priceRows,
    summary: summary, form: form, payKey: payKey, bumpAttempt: bumpAttempt, PAY_FAILED: PAY_FAILED };
})();
