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
