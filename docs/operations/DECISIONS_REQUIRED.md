# Decisions and External Inputs Required

These items do not block repository construction, but they block production activation or final business logic.

1. Legal operating role: licensed broker/intermediary, technology provider, payment collector, or combination; named licensed entities and approved jurisdictions.
2. Fiduciary funds: bank/payment account ownership, permitted safeguarding model, settlement authority and signatory controls.
3. Carrier agreements: products, commission bases, taxes/levies, underwriting authority, issuance SLA, cancellation/refund and clawback rules.
4. Payment provider: selected primary/secondary provider, API/Webhook specifications, signing mechanism, fees, reversal/chargeback and settlement files.
5. Partner attribution: exclusivity, duration, renewal rights, customer consent, portability, inactivity and dispute rules.
6. Agent withdrawal: minimum/maximum, vesting delay, reserve/holdback, KYC tier, fees and approval thresholds.
7. Physical sticker/certificate: issuer, serial allocation, stock custody, printing authorization, delivery SLA and proof requirements.
8. Data governance: controller/processor roles, retention schedule, hosting location, cross-border transfers and data-subject procedures.
9. Brand: final logo assets and any locked brand colors beyond the proposed system.
10. Mobile scope order: B2C first or B2C + agent mode in the first Expo release.

Until approved, each is represented as versioned configuration or an adapter contract rather than hard-coded behavior.
