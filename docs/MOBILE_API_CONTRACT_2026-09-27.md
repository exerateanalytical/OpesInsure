# Mobile app ↔ API contract audit — 2026-09-27

Scope: every `api(...)`, `apiPage(...)` and `fetch(...)` call in `mobile app/src` (api/client.ts, customer.ts, partner.ts, crm.ts, workflow.ts, extra.ts, account.ts, regulatory.ts, policyDocuments.ts). The calls were matched against the live route table (`/api/v1`, 1,475 routes), and the response keys were checked by calling the endpoints from Pest with realistic fixtures.
Guard: `tests/Feature/Mobile/MobileApiContractTest.php`.

## 1. Route coverage

About 225 distinct method+path calls were extracted. **Every call resolves to a backend route with a matching method.** The extractor also flagged about 30 more, and each of those turned out to be a false positive:

- The regex picked up the method of the next call, e.g. `devices()` followed by `revokeDevice` (DELETE). The real route exists.
- Query strings are built inline, e.g. `/crm/leads?…`, `/mobile/documents?owner_type=…`, `/public/institutions?type=…`, `/mobile/policies/{id}/documents?…` and `/pack`, `/mobile/policy-service-requests?policy_id=…`, and the vehicle `models` and `variants` lookups. The routes exist.
- `/public/demo-accounts` is registered only when `config('demo.enabled')`. The app catches the 404 and hides the demo affordance, which is by design.
- `/auth/mobile/refresh` is called through raw `fetch`. The route exists.

Middleware and permissions:
- Customer endpoints (`mobile/*`, `quotes/*`, `proposals/*`, `policies/*`, `payments/*`) run under `auth:api, tenant, json.api`. Ownership is enforced in the services (PartyResolver).
- Partner workspace endpoints are permission-gated:
  - agent: `agent.clients.read` / `agent.clients.manage`
  - broker: `broker.portal.read`, `broker.finance.read`, `broker.claims.file`
  - carrier: `carrier.dashboard.read`, `carrier.referrals.*`, `carrier.claims.read`, `carrier.finance.read`, `carrier.authority.manage`
- `carrier/quote-requests*` uses `carrier.quote_requests.*` and `crm/leads*` uses `crm.leads.*`.
- `public/*` endpoints are anonymous. `public/institutions`, `public/institutions/{id}` and `public/insurance-classes` sit inside the auth group but opt out with `withoutMiddleware(['auth:api','tenant'])`. The app calls them with `anonymous: true`, and the contract test covers this.

## 2. Response-field gaps found and fixed

| Screen | Endpoint | App reads (type) | Gap | Fix |
|---|---|---|---|---|
| Documents | `GET /mobile/documents`, `GET /mobile/documents/{id}` | `SecureDocument`: owner_type, owner_id, label, status, issued_at, expires_at, share_reference | Raw `Document` rows were returned with none of these keys, so the document screen showed an empty title and reference | `MobileDocumentService::present()` adds them on top of the raw row (raw keys are kept) |
| Documents | `POST /mobile/documents/{id}/access` | `signed_url`, plus it re-renders from the response (`setData(x)`) | Only `{id,url,expires_at}` came back, so "Open" always showed "no file" | Response is now `present()` + `url` + `signed_url` (same URL). `url` and `expires_at` are unchanged |
| Documents | `GET /mobile/documents?owner_type=&owner_id=` | owner filter | Query was ignored | `list()` filters on POLICY → `policy_id`, CLAIM → `claim_id` |
| Quotes history / home | `GET /mobile/quotes` | `CustomerQuoteSummary`: offer_count, lowest_total_minor, product_name, vehicle_label, can_resume | Missing, so no offer count, no price, and the Resume state came only from the client-side fallback | `QuoteService::listSummary()` is merged into each paginated row. `can_resume` reuses `assertResumable` through a new `isResumable()` |
| Proposals list | `GET /mobile/proposals` | `ProposalSummary`: proposal_number (+ terms_snapshot, quote_offer_id, submitted_at, decided_at) | proposal_number was missing, so the list subtitle had no reference | Added to `MobileProposalService::present()` |
| Proposal detail | `GET /proposals/{id}` | `policy_id`, `carrier_logo_url` | Missing, so there was no link to the issued policy and no logo | Added to `ProposalController::show` (same values as the mobile list row) |
| Wallet / policy detail | `GET /mobile/wallet`, `GET /mobile/wallet/policies/{id}` | `risk_asset {id,label,registration_number}` (motor detection, claim subtitle) | Missing | Added to `MobileWalletService::summary()`, taken from `proposal.offer.quote.riskAsset` (eager-loaded) |
| Partner book proposals | `GET /mobile/partner/{agent,broker}/proposals` | `carrier_logo_url`, `policy_id` | Missing (reported by the mobile agent) | Added to `PartnerWorkspaceShapes::proposal` |
| Partner book claims | `GET /mobile/partner/{agent,broker}/claims` (also carrier claim detail) | `carrier_name`, `carrier_logo_url` | Missing (reported by the mobile agent) | Added to `PartnerWorkspaceShapes::claim`; `PartnerBookQuery::claims` eager-loads `policy.carrier.party` |

The issued-policy lookup now lives in one place, `Proposal::issuedPolicyId()`, which is used by the mobile proposal row, the proposal detail and the partner proposal shape.

All changes are additive: existing keys are untouched and no routes were added, so `docs/api/openapi.json` was not regenerated.

## 3. Checked and already matching

- **Wallet list/detail:** Policy keys plus carrier_name, carrier_logo_url, product_name, line_code. The detail also has certificates, documents, certificate and delivery. The list does not carry documents or delivery. The app reads those only on the detail screen.
- **Policy documents** (`/mobile/policies/{id}/documents`): policy, groups[].documents[], packs, pack_download_url.
- **Quote detail** (`/quotes/{id}`): `{quote, offers[]}`, where offers include carrier.party, product and carrier_logo_url. `quote.referral_reason` is optional in the app and absent unless the quote was referred.
- **Claims:** list (paginated) and detail include incident_at, incident_location and description (model appends), plus policy and carrier_logo_url. The detail also has can_withdraw.
- **Partner book** (agent and broker quotes, policies, proposals and claims; `PartnerWorkspaceShapes`): the keys match `PartnerQuote`, `PartnerPolicy`, `PartnerProposal` and `PartnerClaim` exactly. Client documents match `ClientDocument`.
- **Directory** (`/public/institutions`, `/public/institutions/{id}`): these match `Institution`, including the register provenance, contacts, head_office and branches.

## 4. Remaining notes (not changed)

- `WalletPolicy.insured_object` is not sent. The app falls back to `risk_asset` or `terms_snapshot.risk_facts` (`claimProduct.ts`).
- `InsuranceApi.policy` (`GET /policies/{id}`, the staff endpoint) is marked deprecated in the app. It is only used by `useRenewal` next to `WalletApi.policy` in `Promise.allSettled`, so the missing carrier and proposal relations there are harmless.
- `DocumentsApi.list` is typed `SecureDocument[]`, but `/mobile/documents` returns a Laravel paginator (unchanged). No screen calls `list` today; if one does, it should use `apiPage`, as `useClaims` already does by reading `result.data`. This is an app-side item for the mobile owner.
- `apiPage` normalises both envelope shapes, Laravel paginator (`data.data`) and `MobileList` (`data` + `meta`/`pagination`), so both are valid for list screens.
