# Staged Rollout and Rollback

1. Build from a tagged, reviewed commit with the `production` EAS profile.
2. Run the release doctor with production environment values. Placeholders must fail the gate.
3. Distribute to internal Android and TestFlight cohorts; validate authentication, payment-status recovery, issuance, claims, push routing and offline synchronization.
4. Promote through 5%, 20%, 50% and 100%. Record approver, timestamp, dashboards and known issues at each stage.
5. Halt rollout for security findings, tenant-data exposure, elevated crashes, refresh-token failures, duplicate payments, status divergence, failed policy issuance or corrupted offline replay.
6. Disable affected server capabilities through runtime bootstrap. Do not claim a client-side rollback changed already-posted financial or insurance state.
7. Roll back the store release or ship a corrected build. Preserve audit, reconciliation and incident evidence.
8. Notify customers and partners using approved bilingual templates when impact is material.

The backend remains authoritative during rollback. Mobile UI state never reverses a payment, policy, claim, commission or settlement.
