# OpesInsure mobile app — checklist audit, second run (app v1.2.1, backend r20260924-142023)

Read-only audit of the owner's checklist (sections 1–25), the customer journey narrative, bottom navigation, partner menus, state-driven navigation and the "biggest risks" list. Three independent agents; findings from code, `route:list`, `schedule:list` and public GETs. No device run.

Legend: **Working** · **Partial** · **UI only** · **Missing** · **Broken**.

**Verdict: not production-ready.** Sign-in, claims and role routing are strong. Security, demo/production separation, payment→policy, comparison and notifications are not.

---

## A. Critical blockers

| # | Blocker | Evidence |
|---|---|---|
| 1 | **Customers can read other customers' data.** All self-registered customers share one tenant; core routes check the tenant only. `GET /customers` and `/customers/{id}` (contacts, national-ID numbers), `GET /policies` (all policies), `/risk-assets`, `/quotes/{id}`, `/proposals/{id}`, `/payments/{id}`, `/policies/{id}`; writes too (`payments/{id}/initiate`, `offers/{o}/accept`, `proposals/{id}/submit`). `web-experiences/{portal}/dashboard` has no permission check. No test covers it. The app's Policies tab, policy detail and claim picker use the leaking `/policies`. | `routes/api.php:148,174,193`, `CustomerController`, `PolicyController`, `MobileAuthService.php:694-722` |
| 2 | **Production runs in demo mode.** `/public/demo-accounts` publishes phones, `Demo@12345`, OTP `123456`; fake payment adapter; `DemoPurchaseSettler` marks **any** customer's payment paid and self-issues the policy. | `PaymentAdapterRegistry.php:3`, `DemoPurchaseSettler.php:51-82` |
| 3 | **No separate staging/production:** every EAS profile points at the production API. | `eas.json` |
| 4 | **Real payment → policy not wired:** webhook success doesn't request issuance; needs manual Filament steps; no customer notification. | `WebhookProcessingService`, `PolicyIssuanceService.php:46` |
| 5 | **Demo payment screen can stall:** only `/mobile/purchases/{id}/status` runs the demo settler; `payment.tsx` polls `/payments/{id}` and has no exit. | `payment.tsx:19`, `MobilePurchaseController.php:5` |
| 6 | **Screens that break:** Payments list and Wallet list call `.map` on a paginated object; receipt fields blank (`receipt_number`/`issued_at` vs `reference`/`confirmed_at`); confirmation dates missing; wallet detail reads wrong fields; certificate button opens undefined URL (no certificate created at issuance). | `payments/index.tsx`, `wallet/index.tsx`, `receipt.tsx`, `confirmation.tsx`, `wallet/policy/[id].tsx`, `policy/[id].tsx` |
| 7 | **Refunds always fail (422):** app sends `{reason}`; backend requires `amount_minor`, `reason_code`, `idempotency_key`. | `client.ts:1314`, `MobilePaymentController.php:36-41` |
| 8 | **Three scheduled commands don't exist:** `policies:notify-expiry`, `settlements:prepare`, `reconciliation:run` — fail daily; no renewal reminders. | `routes/console.php` |
| 9 | **Claim status names differ:** backend `DECLINED`/`PAID`; app checks `REJECTED`/`SETTLED` — the Appeal button can never appear. | `claim/[id].tsx:38,40,85` |
| 10 | **Retries make a new idempotency key** → duplicate payments/claims possible after a timeout. | `client.ts:198,211` |
| 11 | **Customer offline claim drafts can never sync** (sync endpoint needs an agent permission). | `claim/[id]/incident.tsx:71`, `config/permissions.php:146` |
| 12 | Quote resume opens offers without loading them. | `quotes/[id].tsx:40` |
| 13 | App links broken: `/.well-known/assetlinks.json` and AASA return 404. | live |
| 14 | No push sender in the backend; almost no events create notifications. | grep `UserNotification::notify` |

---

## B. Section scoreboard

| § | Section | Verdict | Key gaps |
|---|---|---|---|
| 1 | Branding | Mostly working | Logo drawn in code (no asset); splash navy not white; no native splash; no dark mode; offers are a list not snap cards |
| 2 | Entry | Mostly working | First run not remembered; "Get Started" goes to sign-in |
| 3 | Auth | Working | No OTP countdown; no email resend; no "sign out everywhere"; no locked-account screen |
| 4 | Profile | Partial | No full address, selfie, occupation, beneficiaries, consent UI; saved assets not used in quotes |
| 5 | Home | Mostly missing | 4 hard-coded tiles + 1 policy; no quotes in progress, renewals, claims, providers, help; CTA reads "Compare offers" |
| 6 | Categories | Partial | Motor/Health/Travel/Home/Life only; no Business/Accident/More; hard-coded 4-field forms |
| 7 | Search | Missing | No search, filters, sort, saved searches (app and backend) |
| 8 | Quote | Partial | Calculate + offers work; no insured-person choice, refresh, expiry state, REFERRED state; resume bug |
| 9 | Comparison | Mostly missing | Server sends limits/excess/riders/exclusions/tax/fees; app shows total + validity only; no side-by-side |
| 10 | Proposal | Partial | No applicant/risk review, document upload, signature; no more-info/declined/counter-offer handling |
| 11 | Payments | Partial / demo | MoMo/Orange flow and webhooks exist; demo stall; no card/bank/cancel; refund broken; receipt blank |
| 12 | Issuance | Partial | No certificate, PDF or QR at issuance; detail lacks insurer/coverage/insured object |
| 13 | Wallet | Broken | Wallet list crashes; no filters; no provider contacts; leak (A1) |
| 14 | Renewals | Partial | Renewal quote + compare work; no reminders, lapse, grace; not linked to previous policy |
| 15 | Claims | Mostly working | Best area; typed dates, no GPS, no claim messaging, buttons not status-gated, wrong status names |
| 16 | Notifications | Mostly missing | Inbox works; no push sender; few events |
| 17 | Support | Partial | Tickets + admin contacts work; no FAQ/help centre, escalation, claim/payment linking |
| 18 | Partner access | Partial | Routing + dashboards work; menus incomplete (see D); backend features from waves 6–11 not surfaced |
| 19 | Security | Partial | Secure storage good; attestation fake; no root detection or pinning; consent ref fabricated on device; MASVS 0/9 closed |
| 20 | Offline | Partial | Banner, queue, payment safety OK; no cache, resumable uploads; customer drafts can't sync |
| 21 | Localization | ~3% | 4 of 121 screens translated; XAF/date helpers barely used; terms English-only |
| 22 | Accessibility | Partial | Few labels; no reduced motion; limited font scaling |
| 23 | API | Mostly working | No pagination; retry idempotency flaw; no OpenAPI types for mobile routes |
| 24 | Testing | Weak (mobile) | Mobile tests are source regex checks; Maestro flow stale; no cross-customer tests in backend |
| 25 | Release | Partial | APK sideload only; no iOS; app links 404; no crash reporting; version drift |

---

## C. Customer journey (owner's narrative)

| Step | Result |
|---|---|
| White heritage splash | **Fail** — navy |
| Onboarding 3 messages; "marketplace, not insurer" | Pass |
| Get Started → signup/login | Partial — goes to sign-in |
| Signup: Customer, Name, Phone, Email, Password, Terms | Pass |
| OTP verification | Pass when enabled (currently lifted by design) |
| Basic onboarding/KYC after signup | **Fail** — KYC only from Account |
| Home answers buy / have / attention / help | Partial — buy + have only |
| Home areas (search, quotes, renewals, claims) | Partial |
| Largest CTA "Compare Insurance" | Partial — "Compare offers" |
| Motor: vehicle → usage → owner → cover → info | **Fail** — one 4-field screen |
| Motor fields (make/model, year, value, cover type, history) | **Fail** |
| Creates quote request | Pass |
| Offers from multiple providers | Pass |
| Offers show coverage, excess, benefits, exclusions, wording | **Fail** — total + validity only |
| Compare view | **Fail** |
| Proposal shows premium, taxes/fees | Pass |
| Proposal shows coverage, dates, excess, exclusions, required docs | **Fail** |
| Accept declarations/terms → Continue to payment | Pass |
| Choose MTN MoMo / Orange Money | Pass |
| Initiated → Awaiting → Confirmed, server authoritative | Pass live-provider path; **fails in demo** (A5) |
| "Payment successful" → issuance begins | Partial |
| "You're covered" | Partial — dates likely blank |
| Receive policy, certificate, receipt, documents | **Fail** |
| Policy enters wallet | **Fail** — wallet list crashes; Policies tab leaks |
| Wallet shows provider, coverage, premium, assets, claims | **Fail** |
| Actions: documents / renew / claim / contact provider | Partial — no contact provider |
| Renewal push before expiry | **Fail** |
| Renew this policy / Compare renewal offers | Pass |
| File claim from tab or policy, no re-entry | Pass |
| What/when/where/type/photos/reports/third party | Partial |
| Submit → claim reference | Pass |
| Claim timeline | Partial — server events, no fixed tracker |
| Insurer requests become actionable | Partial — checklist, no alert |

---

## D. Navigation and roles

Customer bottom nav — expected Home | Explore | Policies | Claims | Profile; actual Home | **Compare** | Policies | Claims | **Account**.

| Role | Expected | Present | Missing |
|---|---|---|---|
| Agent | Dashboard, Leads, Quotes, Customers, Policies, Renewals, Commissions | Dashboard, Customers, Sales (quotes), Renewals, Wallet (commissions) | Leads, Quotes list, Policies |
| Broker | Dashboard, Customers, Sales, Quotes, Policies, Claims, Staff, Commissions/settlements | Dashboard, Customers, Production (sales), Receivables + statements | Quotes, Policies, Claims, Staff |
| Insurer | Dashboard, Products, Quotes/proposals, Underwriting, Policies, Claims, Payments/reconciliation, Distribution partners | Dashboard, Referrals (underwriting), Issuance, Claims (read-only), Settlements, Bordereaux | Products, Quotes/proposals, Policies, Payments/reconciliation, Partners; claim actions |

Customers never see partner menus in the app (UI guard works) — but the backend leaks data (A1).

## E. State-driven navigation

| Entity | Backend statuses | App acts on | Gap |
|---|---|---|---|
| Quote | OFFERED, REFERRED, ACCEPTED… (strings, no enum) | RATED, REFERRED, EXPIRED | No Draft/Quoting/Declined/Converted |
| Proposal | DRAFT, DISCLOSURES_PENDING, DOCUMENTS_PENDING, SUBMITTED, UNDER_REVIEW, APPROVED, COUNTEROFFERED, DECLINED, PAYMENT_PENDING | MORE_INFORMATION, UNDER_REVIEW (partly) | Counter-offer/declined unhandled; no Withdrawn |
| Payment | CREATED, PENDING_CUSTOMER, PROCESSING, SUCCEEDED, FAILED, EXPIRED, REFUND_PENDING, REFUNDED, CHARGEBACK_* | SUCCEEDED, FAILED, pending | No Cancelled; refunds/chargebacks not shown |
| Policy | PENDING_PAYMENT, PAID_PENDING_ISSUANCE, ACTIVE, SUSPENDED, CANCELLATION_PENDING, CANCELLED, EXPIRING, EXPIRED, ENDORSEMENT_PENDING | ACTIVE, SUSPENDED, EXPIRED | Pending-issue states |
| Claim | DRAFT … DECLINED, PAID, DISPUTED, CLOSED (12) | REJECTED, SETTLED (wrong names) | A9 |

## F. Owner's biggest risks

| Risk | Verdict |
|---|---|
| API connectivity | OK |
| Provider normalization | Partial — backend normalizes offers; app doesn't show it |
| Payments | **Fail** — demo auto-settlement live; retry idempotency; refund broken |
| Issuance | **Fail** — demo self-issues; real path manual; no certificate/PDF |
| Claims interoperability | Partial — status mismatch; insurer side read-only |
| RBAC leaks | **Fail (critical)** — A1 |
| Offline false success | Mostly OK in app; server demo settlement fakes success |
| CIMA terminology / certificates | Partial — no French, no certificate generation |
| Demo vs production separation | **Fail** — A2, A3 |

## G. Waves

Delivered backend waves 0–11 are all merged (Waves 8–11 per `OPESINSURE_WAVES_08_TO_11_MERGE_GUIDE.md`: routes, migrations 000017–000020, tests). Routes wave12–14 were added later for mobile; `wave15_mobile.php` on 2026-09-24. There is no wave 16–40 in this project (the only 0–40 wave package on this machine is the BND project).

## H. Recommended order
1. A1 data leak (owner scoping + tests) — today.
2. A6, A7, A9, A10, A12 app/backend bugs; A5 demo stall; A8 missing commands.
3. Decide real go-live: payment credentials, A4 payment→issuance→notification, certificate/PDF/QR, then switch demo mode off (A2) and split staging/production (A3).
4. Offer comparison + motor wizard + home dashboard + post-signup KYC.
5. State-driven screens, notifications/push, partner menus.
6. French, accessibility, security hardening, release (app links, iOS, crash reporting), real mobile tests.
