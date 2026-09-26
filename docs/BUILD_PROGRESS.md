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
- 2026-09-26: LIVE r20260926-042840 = master c0d005a (cloud Batches 7-17 + decisions + directory + data master + canonical documents/web UI + letterheads). Verified: verify-live 75/76, purchase POL-2026-000005, FNOL 201, schedule:list OK, stamp-duty schedules DRAFT.
- NEXT: Gap Closure Pack (database/data/gap_closure_2026, 8 agents done, uncommitted in the local tree): merge master, one combined test run in ../opesinsure-test, then deploy.
- Phase 1 LIVE (26c8b9a).
- Phase 2 (676908c) LIVE (release r20260925-034108): public website + legal pages, cases/tasks/SLA, RBAC, approvals, organisations + timezones, route aliases, vehicle Africa config, selection-first forms.
- APK 1.3.0 LIVE on /download.
- OTA published to channel production-apk (runtime 1.3.0): update group 6175be73-cb09-42c9-bd8f-219c6cda734e.

## Mobile ownership (2026-09-25)
The owner is opening a separate session for the app. Once it has started, it OWNS "mobile app/", including OTA publishing (npm run update:apk) and APK builds. This backend session does not edit the app or publish updates; it only deploys the backend and adds needed app changes to the list below.

### Mobile backlog (for the app session)
- Insurer directory: prefer verification_label {en,fr} from the API in src/lib/institutions.ts. The screens are built but uncommitted in the app tree and not yet published; publish after the backend deploy that adds the directory.
- Manual quotes: waiting states WAITING_FOR_CUSTOMER and WAITING_FOR_EXTERNAL_EVIDENCE, the PLATFORM_SLA label on SLA clocks, and case_family/case_subtype.
- Next native APK: expo-file-system + expo-sharing so authenticated PDFs open reliably on Android; 1.3.0 audit native items (expo-screen-capture, device integrity, Sentry, certificate pinning, FCM google-services).
- KYC: collect source of funds/wealth once a customer endpoint exists (POST /v1/kyc/submissions/{id}/sources is staff-only today).

## Next
- Deploy note: the canonical UI work adds composer package mallardduck/blade-lucide-icons. The deploy snapshot needs `composer install --no-dev` (Windows needs --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix) before building the tarball.
- BEFORE deploying the canonical document work (cs1): run the live purchase journey against a local copy. cs1 blocks documents with missing required fields (motor attestation needs VIN/make/model/use; schedules need coverage lines + taxes; receipts need a reconciled payment), which could stop customers getting certificates in the demo flow. The Lucide package change (cs2) must be complete in composer.json before any full run.
- Mobile request: QuoteRequestController::present() (list + detail) should add case_status, case_family, case_subtype, plus label (deadline_label PLATFORM_SLA|REGULATORY_DEADLINE) on each sla[] clock. Not blocking.
- Duplicate lists to consolidate (found by wm2): aviation.manufacturer vs aviation_insurance.manufacturer; aviation.aircraft_type vs aviation_insurance.aircraft_category; persons.relationship vs life_insurance.relationship (persons canonical); master-data person_role repeats 4 party roles. Two bordereau endpoints accept different type sets (finance batches).
- Behaviour change in the next deploy: VehicleUsageMapper now rates owner code PRIVATE as PRIVATE (it was COMMERCIAL). Motor quotes sent with usage=PRIVATE will change price.
- Hook the authority-type check and the premium-to-cover evaluator into issuance, policies and the authority engine (Batch 7). Mobile: manual-quote waiting states WAITING_FOR_CUSTOMER/WAITING_FOR_EXTERNAL_EVIDENCE and the PLATFORM_SLA label.
- Mobile (before the directory OTA): prefer verification_label{en,fr} from the API over the built-in badge text in src/lib/institutions.ts.
- Next APK (native): add expo-file-system + expo-sharing so authenticated PDFs (quote PDF, documents) open reliably on Android; plus the 1.3.0 audit native items (expo-screen-capture, integrity, Sentry, pinning, FCM).
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
3. LIVE: phases 1-6, decisions batch (r20260925-144020), cloud Batches 7-9 (r20260925-163340). master 4ac5273 = cloud Batches 10-17 + local canonical documents/UI + letterheads, full suite running in ../opesinsure-test (DB opesinsure_test_b1017), then deploy per docs/LOCAL_SESSION_RESUME.md section 3 (release guides BATCH9 → 10 → 11_12 → 13_17; stamp-duty schedules stay DRAFT; schedule:list; verify-live + purchase + FNOL). 8 agents (gp1-gp8) are implementing the Gap Closure Pack in database/data/gap_closure_2026 for the NEXT deploy.
- Deploy note: run composer dump-autoload in the deploy snapshot before building the tarball (classes were deleted in Batch 6).
- Batch 6 follow-ups: 7B UnderwritingService::decide → ProposalService::applyUnderwritingDecision; 7C/7D issuance → PolicyIssuabilityService::assertIssuable + CoverTermsService::resolveStart; mobile: carrier "Quote requests" screens + "sent to insurer" state; INFORMATION_REQUIRED/RESUBMITTED labels, resubmit + withdraw screens, 422 after submission, DECLINED/EXPIRED quote statuses. Wave10Controller::saveComparison should delegate to QuoteComparisonService or become an alias.
- More follow-ups from Batch 5: hook RuleEngine::assertComplete into bind/issue/claim (Batches 6-7, 11); DocumentRequirementService::applicable should use the rules engine (DOCUMENTS domain); ProposalService should read PROPOSAL question sets instead of disclosure_schema_versions directly; RiskFactsProcessor and RiskAssetTypes should read via QuestionSetCatalogue; Filament UI for rules and question sets.
