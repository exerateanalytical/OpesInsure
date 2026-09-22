# Batch 04 Security and Evidence Matrix

| Control | Enforced now | Remaining production gate |
|---|---|---|
| Reconciliation duplicate prevention | Provider statement reference, file hash and row reference uniqueness | Signed provider statement ingestion and automated scheduled fetch |
| Matching integrity | Reference, currency and exact integer amount checks | Configurable tolerance only where contractually permitted |
| Exception resolution | Permission-controlled resolution with actor/time/notes | Dual approval for financial adjustments |
| Settlement segregation | Database and API prevent preparer from approving | Bank instruction adapter, signing authority and acknowledgement |
| Claim lifecycle | Guarded transitions and append-only events | Carrier-specific SLA and decision adapters |
| Loss eligibility | Loss timestamp checked against coverage period | Timezone normalization and carrier grace rules |
| Document integrity | SHA-256, version history, MIME/size limits and duplicate detection | Direct private upload, antivirus and OCR workers |
| Evidence access | Tenant scope, clean-scan requirement and purpose access log | Short-lived signed storage URL adapter |
| Certificate integrity | Unique serial, template version, document hash, random verification token | Digitally signed PDF and key custody/HSM |
| Sticker custody | Unique stock serial, controlled assignment and custody events | Barcode scanning, carrier stock import and physical count reconciliation |
| Public verification privacy | Minimal policy validity data only; rate limited | Privacy/legal review of displayed fields |

