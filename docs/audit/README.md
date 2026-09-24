# Live audit scripts

Node 22+, no dependencies. Run from the repo root.

| Script | Purpose |
|---|---|
| `verify-live.mjs` | Remediation checks (plan Phases 6 + 9): public endpoints, OTP + password login for every demo persona, portal endpoints, customer-vs-partner 403s, carrier referral scoping, OTP rate limit removed, registration without verification, forgot password, step-up with the demo code. Prints PASS/FAIL/WARN and exits 1 on any FAIL. |
| `replay.mjs` | Logs in as each demo persona and replays every screen-load GET the app makes. |
| `journey.mjs` | Full customer purchase + claim journey. |

## Running

```bash
# Against production (default BASE)
node docs/audit/verify-live.mjs

# Against a local backend
BASE=http://localhost:8000/api/v1 node docs/audit/verify-live.mjs
```

PowerShell: `$env:BASE='http://localhost:8000/api/v1'; node docs/audit/verify-live.mjs`

Optional env: `DEMO_PASSWORD` (default `Demo@12345`). `replay.mjs` / `journey.mjs` use `API_BASE`; `verify-live.mjs` accepts either `BASE` or `API_BASE`.

Needs demo mode on the target (`/public/demo-accounts` gives the personas and OTP, 123456).

## Side effects

`verify-live.mjs` writes data: it registers one random `+2376XXXXXXXX` account, creates OTP, password-reset and step-up challenges, and adds device sessions for each persona. The 25-request OTP burst would lock out the customer demo phone if a rate limit were still active.

## WARN vs FAIL

- WARN: `/public/institutions` returns 404 (not deployed yet); password login for a demo persona fails (demo password may not be seeded); a customer probe of a `{id}` route returns 404 instead of 403 (model binding runs before the permission check, so nothing leaks); carrier referral rows have no carrier field to compare.
- Everything else is FAIL.
