# ADR-004: Money is stored as integer minor units in `*_minor` columns

- Status: Accepted (2026-09-24)
- REQ: REQ-ARC-007, financial requirements (BP X)

## Decision
- Every monetary amount is a `bigInteger` column suffixed `_minor` (e.g. `amount_minor`, `premium_minor`, `tax_minor`, `commission_minor`) holding the amount in the currency's minor unit, alongside a `currency` (ISO 4217) column on the row or its parent.
- XAF/XOF have zero decimals: minor = major. Code must use the currency's exponent, never assume ×100.
- No `float`/`double` for money anywhere; `decimal` is only permitted for *rates/percentages* (e.g. tax rate, commission rate), never amounts.
- Rounding happens once, at the calculation boundary, with the rule recorded on the tariff/tax version.
- API payloads expose `*_minor` integers plus `currency`; formatting is a presentation concern.

## Consequences
- New money columns not ending in `_minor` are rejected in review.
