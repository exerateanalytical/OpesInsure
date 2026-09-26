// Claims area helpers shared by /account/claims, /account/claims/new and /account/claims/<id>.
// Status grouping mirrors the claim state machine (app/Domain/Claims/ClaimStateMachine.php).
(function () {
  var T = window.OPES_CLAIMS || {};
  var h = Opes.h, icon = Opes.icon;

  var STAGE = { DRAFT: 0, SUBMITTED: 0, ACKNOWLEDGED: 1, EVIDENCE_PENDING: 1, CARRIER_REVIEW: 1, ASSESSMENT: 2, DISPUTED: 2,
    APPROVED: 3, PARTIALLY_APPROVED: 3, DECLINED: 3, PAID: 4, CLOSED: 5, WITHDRAWN: 5 };
  var STAGES = ['submitted', 'review', 'assessment', 'approval', 'payment', 'closed'];
  var TONE = { DRAFT: 'muted', SUBMITTED: 'info', ACKNOWLEDGED: 'info', EVIDENCE_PENDING: 'warn', CARRIER_REVIEW: 'info', ASSESSMENT: 'info',
    DISPUTED: 'warn', APPROVED: 'ok', PARTIALLY_APPROVED: 'ok', PAID: 'ok', CLOSED: 'muted', WITHDRAWN: 'muted', DECLINED: 'bad' };

  function up(s) { return String(s || '').toUpperCase(); }
  /** Status shown to the customer: a claim they withdrew is stored CLOSED (ClaimMachine `withdraw`) but reads "Withdrawn". */
  function shown(c) { return c && c.withdrawn_at ? 'WITHDRAWN' : up(c && c.status); }
  /** Claimant may withdraw only before assessment (mirrors ClaimMachine::WITHDRAWABLE; the API also returns can_withdraw). */
  var WITHDRAWABLE = ['SUBMITTED', 'ACKNOWLEDGED', 'EVIDENCE_PENDING'];
  /** Tab bucket: progress | approved | rejected | draft | closed */
  function bucket(c) {
    var s = shown(c);
    if (s === 'DRAFT') return 'draft';
    if (s === 'WITHDRAWN') return 'closed';
    if (s === 'DECLINED') return 'rejected';
    if (s === 'APPROVED' || s === 'PARTIALLY_APPROVED' || s === 'PAID') return 'approved';
    if (s === 'CLOSED') return c.approved_amount_minor ? 'approved' : 'closed';
    return 'progress';
  }
  function fmt(str, v) { return String(str || '').replace(/:(\w+)/g, function (m, k) { return v && v[k] !== undefined ? v[k] : m; }); }
  function statusLabel(s) { return (T.status || {})[up(s)] || Opes.label(s); }
  function chip(s) {
    var tone = TONE[up(s)] || 'muted';
    return h('span', { class: 'st st-' + tone }, icon(tone === 'ok' ? 'check' : tone === 'bad' ? 'x' : 'clock'), statusLabel(s));
  }
  function incidentType(c) {
    var ld = c.loss_details || {}; var t = (ld.incident && ld.incident.incident_type) || ld.incident_type || c.incident_type || '';
    return up(t);
  }
  function typeLabel(code) { code = up(code); if (!code) return '—'; return (T.types || {})[code] || Opes.label(code); }
  var TYPE_ICON = { COLLISION: 'motor', ACCIDENT: 'accident', THEFT: 'lock', FIRE: 'bulb', NATURAL_DISASTER: 'globe', VANDALISM: 'x',
    GLASS_DAMAGE: 'eye', WATER_DAMAGE: 'home', PROPERTY_DAMAGE: 'home', MEDICAL: 'health', TRAVEL: 'travel', OTHER: 'dots' };
  function typeIcon(code) { return TYPE_ICON[up(code)] || 'doc'; }
  function terms(p) { return (p && p.terms_snapshot) || {}; }
  function line(p) { return up(terms(p).line_code); }
  function lineLabel(p) { var l = line(p); return (T.lines || {})[l] || terms(p).product || (l ? Opes.label(l) : ''); }
  function risk(p) { return terms(p).risk_facts || {}; }
  /** "Toyota Corolla 2019" / product name. */
  function itemTitle(p) {
    var r = risk(p);
    var v = [r.make, r.model, r.year].filter(Boolean).join(' ');
    return v || terms(p).product || lineLabel(p);
  }
  function itemSub(p) { var r = risk(p); return r.registration_number || (p && p.policy_number) || ''; }
  function insurer(p) { var c = p && p.carrier; return (c && (c.short_name || c.trade_name || c.legal_name)) || ''; }
  function lineIcon(p) { return ({ MOTOR: 'motor', HOME: 'home', TRAVEL: 'travel', HEALTH: 'health', LIFE: 'life', BUSINESS: 'business' })[line(p)] || 'shield'; }
  function amount(minor) { return minor === null || minor === undefined ? '—' : Opes.money(minor, { minor: true }); }

  /** Fetch every page of a Laravel paginator endpoint (capped). */
  function all(path, cap) {
    var out = [];
    function next(page) {
      return Opes.api(path, { raw: true, query: { page: page } }).then(function (j) {
        var d = j && j.data !== undefined ? j.data : j;
        var items = Array.isArray(d) ? d : (d && d.data) || [];
        out = out.concat(items);
        var last = d && d.last_page ? d.last_page : 1;
        if (page < last && page < (cap || 10)) return next(page + 1);
        return out;
      });
    }
    return next(1);
  }

  var MAX = 10 * 1024 * 1024;
  var MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
  function fileOk(f) { return f && f.size <= MAX && MIMES.indexOf(f.type) >= 0; }
  /** Same path as the mobile app: POST /mobile/documents (base64) then link as claim evidence. */
  function uploadEvidence(claimId, file, evidenceType) {
    return Opes.fileBase64(file).then(function (b64) {
      return Opes.api('/mobile/documents', { method: 'POST', body: { category: 'CLAIM_EVIDENCE', mime_type: file.type, file_base64: b64 } });
    }).then(function (doc) {
      return Opes.api('/mobile/claims/' + encodeURIComponent(claimId) + '/evidence', { method: 'POST', body: { document_id: doc.id, evidence_type: evidenceType || 'OTHER', purpose: 'CLAIM_EVIDENCE' } });
    });
  }
  function size(b) { if (!b && b !== 0) return ''; return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  // Evidence types per line, same keys as GET /mobile/claims/{id}/evidence-requirements (MobileClaimCompletionController).
  var EV = { MOTOR: ['DAMAGE_PHOTO', 'POLICE_REPORT', 'DRIVER_LICENCE', 'REPAIR_ESTIMATE', 'THIRD_PARTY_DETAILS'],
    HOME: ['DAMAGE_PHOTO', 'PROOF_OF_OWNERSHIP', 'REPAIR_ESTIMATE', 'POLICE_REPORT'],
    TRAVEL: ['MEDICAL_REPORT', 'TRAVEL_DOCUMENTS', 'RECEIPTS'], HEALTH: ['MEDICAL_REPORT', 'INVOICES'] };
  function evidenceTypes(p) { return (EV[line(p)] || EV.MOTOR).concat(['OTHER']); }
  function evLabel(k) { return ((T.wiz || {}).evidence || {})[up(k)] || Opes.label(k); }

  window.OpesClaims = { T: T, shown: shown, WITHDRAWABLE: WITHDRAWABLE, evidenceTypes: evidenceTypes, evLabel: evLabel, STAGE: STAGE, STAGES: STAGES, bucket: bucket, fmt: fmt, statusLabel: statusLabel, chip: chip, incidentType: incidentType,
    typeLabel: typeLabel, typeIcon: typeIcon, terms: terms, line: line, lineLabel: lineLabel, risk: risk, itemTitle: itemTitle, itemSub: itemSub,
    insurer: insurer, lineIcon: lineIcon, amount: amount, all: all, fileOk: fileOk, uploadEvidence: uploadEvidence, size: size, up: up };
})();
