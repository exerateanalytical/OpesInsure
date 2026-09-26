// OpesInsure public site: menu + search toggles, live provider filtering.
(function () {
  function toggle(btnSel, panelId, focusSel) {
    var btn = document.querySelector(btnSel), panel = document.getElementById(panelId);
    if (!btn || !panel) return;
    btn.addEventListener('click', function () {
      var open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', String(open));
      if (open && focusSel) { var f = panel.querySelector(focusSel); if (f) f.focus(); }
    });
  }
  toggle('.menu-btn', 'mobile-nav');
  toggle('.search-toggle', 'site-search', 'input');

  // Provider directory: filter the rendered list instantly; the form still
  // submits to the server without JS.
  var form = document.getElementById('provider-filters');
  if (!form) return;
  var items = Array.prototype.slice.call(document.querySelectorAll('#provider-list > li'));
  var count = document.getElementById('provider-count');
  var empty = document.getElementById('provider-empty');
  function apply() {
    var q = (form.q.value || '').toLowerCase().trim(), t = form.type.value, b = form.branch.value, c = form.city ? form.city.value.toLowerCase() : '';
    var n = 0;
    items.forEach(function (li) {
      var ok = (!q || li.dataset.search.indexOf(q) !== -1) && (!t || li.dataset.kind === t) && (!b || li.dataset.branch === b) && (!c || li.dataset.city === c);
      li.hidden = !ok; if (ok) n++;
    });
    if (count) count.textContent = n;
    if (empty) empty.hidden = n !== 0;
  }
  ['input', 'change'].forEach(function (e) { form.addEventListener(e, apply); });
})();

// Desktop catalogue: auto-submit filters, compare tray counter, list density, provider search, char counter.
(function () {
  Array.prototype.forEach.call(document.querySelectorAll('form.js-autosubmit'), function (f) {
    f.addEventListener('change', function (e) { if (e.target.matches('input[type=checkbox],input[type=radio]')) f.submit(); });
  });
  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-cmp]'));
  var counters = document.querySelectorAll('[data-cmp-count]');
  function countCmp() {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    Array.prototype.forEach.call(counters, function (c) { c.textContent = n ? '(' + n + ')' : ''; });
    boxes.forEach(function (b) { b.disabled = !b.checked && n >= 4; });
  }
  boxes.forEach(function (b) { b.addEventListener('change', countCmp); });
  if (boxes.length) countCmp();
  var rows = document.querySelector('.prows');
  Array.prototype.forEach.call(document.querySelectorAll('.viewtg [data-view]'), function (btn, i, all) {
    btn.addEventListener('click', function () {
      if (rows) rows.classList.toggle('compact', btn.dataset.view === 'compact');
      Array.prototype.forEach.call(all, function (o) { o.setAttribute('aria-pressed', String(o === btn)); });
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-filter-list]'), function (inp) {
    var list = document.getElementById(inp.dataset.filterList);
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase().trim();
      Array.prototype.forEach.call(list.children, function (el) { el.hidden = q && (el.dataset.name || '').indexOf(q) === -1; });
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('textarea[data-count]'), function (t) {
    var out = document.getElementById(t.dataset.count);
    t.addEventListener('input', function () { out.textContent = t.value.length + '/' + t.maxLength; });
  });
})();

// Signed in (session saved by /login, see public/landing/auth.js)? Header "Sign In" becomes "My Account".
(function () {
  var s = null;
  try { s = JSON.parse(localStorage.getItem('opes.web.session') || 'null'); } catch (e) {}
  if (!s || !s.access_token) return;
  Array.prototype.forEach.call(document.querySelectorAll('[data-account-link]'), function (a) {
    a.href = '/account'; a.textContent = a.dataset.labelAccount || 'My Account';
  });
})();
