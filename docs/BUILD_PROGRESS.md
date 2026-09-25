# OpesInsure build progress (resume point)

Updated: 2026-09-25 03:40 (session 5b0d3161)

## Owner standing orders
- Build everything the owner specified (docs/spec/*, docs/spec/TRACEABILITY_MATRIX_V1.md batches 1-17, SCREEN_TO_API_MATRIX_V1.md, docs/audit/MOBILE_INDUSTRY_STANDARD_AUDIT_V1.md, docs/audit/FREE_TEXT_FIELDS_AUDIT.md).
- Never duplicate; search existing first. Blockers go in docs/spec/OWNER_OPEN_QUESTIONS.md, then continue.
- Deploy phase after phase:
  1. full pest suite green;
  2. commit on master;
  3. DB backup (/srv/opesinsure/backup.sh);
  4. rehearse migrations on a copy of prod;
  5. deploy.sh with the tarball built from the C:\laragon\www\opesinsure-deploy-snap worktree;
  6. live checks: node docs/audit/verify-live.mjs (75/76 expected; the legacy /claims check fails).
- Mobile: JS-only changes go over the air with `npm run update:apk` (channel production-apk, runtime 1.3.0); native changes need a new APK on /download.
- Server: opesinsure@187.77.110.114, key ~/.ssh/opesinsure_deploy.

## Status
- Phase 1 LIVE (26c8b9a).
- Phase 2 (676908c) LIVE (release r20260925-034108): public website + legal pages, cases/tasks/SLA, RBAC, approvals, organisations + timezones, route aliases, vehicle Africa config, selection-first forms.
- APK 1.3.0 LIVE on /download.
- OTA published to channel production-apk (runtime 1.3.0): update group 6175be73-cb09-42c9-bd8f-219c6cda734e.

## Next
1. (done) OTA published.
2. Mobile follow-ups:
   - render InputFieldContract `source` pickers in every form (docs/audit/FREE_TEXT_FIELDS_AUDIT.md "Mobile app changes");
   - server-driven forms from GET /api/v1/forms/{form};
   - timezone picker (GET settings/timezones, PATCH me/settings);
   - vehicle suggestions via POST master-data/suggestions;
   - switch POST /mobile/policy-service-requests to /policies/{id}/service-requests.
3. Phase 3 (2788750) LIVE (release r20260925-053120). Mobile OTA ea00d9ea published. Batch 4 IN PROGRESS (4A golden record, 4B KYC, 4C CRM, 4D insured objects + search, 4E web shell + insurer/broker portals).
