# OpesInsure Store Submission Checklist

## Identity and ownership

- Confirm Opesware Technologies owns the Apple and Google developer accounts.
- Confirm `com.opesware.opesinsure` is reserved in both stores.
- Replace the Apple Team ID and Google Play App Signing SHA-256 placeholders.
- Host both association files over HTTPS with the correct content type and no redirects.

## Listing and privacy

- Approve English and French name, subtitle, descriptions and support URLs.
- Capture real-device screenshots for every required phone/tablet size; do not use demo personal data.
- Complete Apple privacy nutrition labels and Google Data Safety from the final data-flow register.
- Publish privacy policy, terms, account deletion, support and regulatory pages.
- Explain camera, documents, notifications, biometrics and location permissions in plain language.

## Release evidence

- Signed Android App Bundle and iOS archive produced by the production profile.
- SBOM, dependency scan and secret scan attached to the release record.
- OWASP MASVS and independent penetration-test findings closed or formally accepted.
- MTN MoMo, Orange Money and carrier sandbox/certification results attached.
- Backup/restore, delayed webhook, provider outage and rollback drills passed.
- Customer support, monitoring and on-call ownership confirmed.

## Rollout

- Start with internal testers, then closed testing/TestFlight.
- Production rollout: 5%, 20%, 50%, 100%, with explicit approval between stages.
- Stop for elevated crash-free-session regression, authentication failures, payment-status divergence, policy-issuance failure or data-isolation findings.
