# Wave 8 — Operations and customer care

Implemented the web/API operational layer for certificate fulfilment, courier custody, delivery attempts and proof; purpose/channel preferences; version-ready templates; queued delivery retry/dead-letter handling; support, complaint and regulatory complaint SLAs; interaction history; tenant-scoped Filament work queues; EN/FR copy; policies; audit/outbox hooks and OpenAPI.

## Acceptance boundary

Static source validation is included. Provider-specific SMS/email/WhatsApp adapters, migrations, worker execution and browser acceptance must run in a PHP/PostgreSQL environment before release. Public complaint intake should be exposed through a separately rate-limited, CAPTCHA-protected boundary rather than these authenticated tenant routes.
