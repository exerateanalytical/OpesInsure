# OpesInsure master workflow map — owner specification (2026-09-24)

One governed lifecycle per insurance record; Customer, Agent and Broker each see a different interface and perform only their permitted actions. The full narrative for each workflow is the owner's message of 2026-09-24 (kept in the session transcript); this index is the canonical numbering.

| # | Workflow | # | Workflow |
|---|---|---|---|
| 1 | Customer onboarding / registration | 26 | Refund |
| 2 | Lead / prospect | 27 | Commission |
| 3 | Product discovery | 28 | Broker-to-insurer settlement |
| 4 | Quotation | 29 | Payment reconciliation |
| 5 | Quote comparison | 30 | Customer service request |
| 6 | Proposal / application | 31 | Complaint |
| 7 | Underwriting | 32 | Communication |
| 8 | Premium acceptance | 33 | Task / follow-up |
| 9 | Payment | 34 | Customer document expiry |
| 10 | Policy issuance | 35 | KYC remediation |
| 11 | Policy management | 36 | Corporate customer |
| 12 | Insurance documents | 37 | Vehicle management |
| 13 | Motor attestation / sticker | 38 | Beneficiary management |
| 14 | Endorsement / avenant | 39 | Notification-to-action |
| 15 | Renewal | 40 | Agent portfolio transfer |
| 16 | Cancellation / termination | 41 | Agent suspension |
| 17 | Suspension / reinstatement | 42 | Broker staff approval (maker/checker) |
| 18 | FNOL | 43 | Policy search / verification |
| 19 | Claim evidence | 44 | Public attestation verification |
| 20 | Claim assessment | 45 | Expired policy |
| 21 | Claim investigation | 46 | Failed policy issuance (idempotent) |
| 22 | Claim decision | 47 | Failed payment |
| 23 | Claim settlement | 48 | Duplicate payment |
| 24 | Claim rejection / appeal | 49 | Renewal paid but issuance failed |
| 25 | Claim reopening | 50 | Customer 360 |

Core lifecycle: Need → Lead → Customer/KYC → Discovery → Quote → Proposal → Underwriting → Final terms → Payment → Issuance → Documents → Servicing → Endorsement → Renewal → FNOL → Evidence → Assessment → Decision → Settlement → Closure → Renewal/Expiry/Termination.

Canonical state lists given by the owner:
- Lead: New → Contacted → Qualified → Quote Started → Quote Sent → Won / Lost / Dormant
- Quote: Draft → Calculated → Generated → Sent → Viewed → Accepted / Declined / Expired
- Proposal: Draft → Submitted → Under Review → Additional Information Required → Resubmitted → Approved / Declined
- Payment: Initiated → Pending → Successful → Failed → Reversed → Refunded
- Policy: Pending Issue → Active → Amended → Suspended → Cancelled / Expired / Renewed
- Sticker: Received → In Stock → Allocated → Assigned → Issued → Active; exceptions Damaged / Lost / Void / Returned
- Endorsement: Draft → Submitted → Under Review → Approved → Financial Adjustment → Issued
- Renewal: Upcoming → Contacted → Quoted → Accepted → Paid → Renewed; or Declined / Lapsed / Lost
- Cancellation: Requested → Under Review → Approved → Financial Adjustment → Cancelled
- Claim settlement: Approved → Payment Pending → Processing → Paid → Settled → Closed
- Settlement batch: Open → Calculated → Awaiting Approval → Approved → Processing → Settled → Reconciled

Next deliverable: docs/spec/WORKFLOW_REGISTER.md — per workflow and actor: ID, module, trigger, prerequisites, screens, actions/buttons, API calls, states, transition guards, notifications, documents, audit events, failure paths, final state, and current implementation status.
