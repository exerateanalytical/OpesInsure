# OpesInsure build progress (resume point)

Updated: 2026-09-25 (session 5b0d3161)

## Owner standing orders
- Build everything the owner specified (docs/spec/*, docs/spec/TRACEABILITY_MATRIX_V1.md batches 1-17, SCREEN_TO_API_MATRIX_V1.md, mobile audit docs/audit/MOBILE_INDUSTRY_STANDARD_AUDIT_V1.md, FREE_TEXT_FIELDS_AUDIT.md).
- Never duplicate; search existing first. Blockers: record in docs/spec/OWNER_OPEN_QUESTIONS.md and continue.
- Deploy phase after phase: full pest suite green -> commit on master -> DB backup (/srv/opesinsure/backup.sh) -> rehearse migrations on a copy of prod -> deploy.sh tarball -> live checks (node docs/audit/verify-live.mjs; 75/76 expected, the old /claims check fails) -> mobile: OTA (npm run update:apk, JS-only) else APK to /download.
- Server: opesinsure@187.77.110.114, key ~/.ssh/opesinsure_deploy. Before deploying Batch 2, add DEMO_ALLOW_IN_PRODUCTION=true to /srv/opesinsure/shared/.env.

## Status
- Phase 1 LIVE (backend 26c8b9a, release r20260924-235554).
- APK 1.3.0 LIVE on /download (md5 ad07319a8731d1e6aa94029e149ec2de, channel production-apk, runtime appVersion 1.3.0).
- In progress (uncommitted in tree): Batch 2 (2A cases/tasks/SLA, 2B RBAC, 2C approvals, 2D organisations + timezone, 2E route aliases), vehicle Africa config + generations/engine variants, no-free-text forms, mobile UI redesign (JS-only, OTA to 1.3.0), landing page + public pages (/privacy, /terms, /account/delete are required by app 1.3.0 and currently 404).
- Next: Batch 3 onward per TRACEABILITY_MATRIX_V1.md §4.
