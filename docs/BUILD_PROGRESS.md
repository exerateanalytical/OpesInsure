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
- Implement docs/spec/OWNER_DECISIONS_2026-09-25.md as a "decisions batch" right after the Batch 6 deploy (before Batch 7).
- Owner decision: NO staging; production keeps demo mode ON. Do not build staging or disable demo.
- Known follow-ups: ClaimController::store raw insert bypasses CapabilityPinner (fix in Batch 11 claims); PaymentExecution adapter not wrapped (Batch 9); mobile agent/broker "what I can sell" list from GET distribution/catalogue.
1. (done) OTA published.
2. Mobile follow-ups:
   - render InputFieldContract `source` pickers in every form (docs/audit/FREE_TEXT_FIELDS_AUDIT.md "Mobile app changes");
   - server-driven forms from GET /api/v1/forms/{form};
   - timezone picker (GET settings/timezones, PATCH me/settings);
   - vehicle suggestions via POST master-data/suggestions;
   - switch POST /mobile/policy-service-requests to /policies/{id}/service-requests.
3. Phases 1-4 LIVE. Phase 5 (4d785dd) LIVE (r20260925-080232; live purchase journey passes end to end). Batch 6 IN PROGRESS (6A product governance + sandbox, 6B quote consolidation, 6C manual quotation mode 1, 6D proposal consolidation). Then Batch 7.
- Deploy note: run composer dump-autoload in the deploy snapshot before building the tarball (classes were deleted in Batch 6).
- Batch 6 follow-ups: 7B UnderwritingService::decide → ProposalService::applyUnderwritingDecision; 7C/7D issuance → PolicyIssuabilityService::assertIssuable + CoverTermsService::resolveStart; mobile: carrier "Quote requests" screens + "sent to insurer" state; INFORMATION_REQUIRED/RESUBMITTED labels, resubmit + withdraw screens, 422 after submission, DECLINED/EXPIRED quote statuses. Wave10Controller::saveComparison should delegate to QuoteComparisonService or become an alias.
- More follow-ups from Batch 5: hook RuleEngine::assertComplete into bind/issue/claim (Batches 6-7, 11); DocumentRequirementService::applicable should use the rules engine (DOCUMENTS domain); ProposalService should read PROPOSAL question sets instead of disclosure_schema_versions directly; RiskFactsProcessor and RiskAssetTypes should read via QuestionSetCatalogue; Filament UI for rules and question sets.
