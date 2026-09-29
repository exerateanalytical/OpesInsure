{{-- S4: shared renderer for uploads still in the malware scan (API `pending` arrays, App\Application\Documents\Scanning\PendingDocuments).
     Usage: @include('public.partials.pending-scan') once per page, then OpesPendingScan.render(items) -> element|null.
     Rows are never downloadable (no open/download control is rendered). --}}
@once
@push('scripts')
<script>
window.OpesPendingScan = (function () {
  var T = {!! json_encode(__('scan_queue.pending'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    [].concat(kids || []).forEach(function (c) { if (c !== null && c !== undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function when(v) {
    if (!v) return '—';
    var d = new Date(v);
    return isNaN(d) ? String(v) : new Intl.DateTimeFormat((document.documentElement.lang || 'fr').slice(0, 2) === 'en' ? 'en-GB' : 'fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Douala' }).format(d);
  }
  function render(items) {
    if (!items || !items.length) return null;
    return el('div', { 'class': 'pending-scan', 'data-pending-scan': '', role: 'status', style: 'margin:12px 0;padding:12px 14px;border:1px dashed #E0B252;border-radius:10px;background:#FFF9EC' }, [
      el('b', { style: 'display:block;font-size:14px;color:#0A1E4D;margin-bottom:6px' }, T.heading),
      el('ul', { style: 'list-style:none;margin:0;padding:0;display:grid;gap:6px' }, items.map(function (p) {
        var bad = p.status === 'INFECTED';
        return el('li', { 'data-pending-status': p.status, style: 'display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline;font-size:13px' }, [
          el('span', { style: 'font-weight:600;word-break:break-all' }, p.filename || '—'),
          el('small', null, T.uploaded_at + ': ' + when(p.uploaded_at)),
          el('span', { style: 'font-size:12px;padding:1px 8px;border-radius:999px;' + (bad ? 'background:#FDE8E8;color:#9B1C1C' : 'background:#FEF3C7;color:#8A5A00') }, p.status_label || T.status[p.status] || p.status),
          el('small', { style: 'flex-basis:100%;color:#5B6B85' }, p.message || (bad ? T.quarantined : T.in_progress)),
        ]);
      })),
    ]);
  }
  return { t: T, render: render };
})();
</script>
@endpush
@endonce
