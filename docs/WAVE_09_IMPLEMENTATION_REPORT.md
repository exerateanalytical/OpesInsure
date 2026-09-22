# Wave 9 — Trust, compliance and regulatory operations

Wave 9 completes five web/API modules: effective-dated fraud rules and review alerts; compliance investigations; data-subject rights; time-boxed privileged access; and versioned regulatory reporting. All tenant records are scoped, mutable workflows use row locks or guarded transitions, repeated commands are hash-bound to idempotency keys, and sensitive approvals enforce maker–checker separation.

No statutory deadline, threshold or regulator-specific payload was invented. Deadlines and schemas are supplied as governed configuration. Regulatory transmission supports approval, retry, acknowledgement and reversal. Filament provides consistent trust-operations queues and record actions. Audit/outbox records cover decisions and externally consequential transitions.

Runtime migrations and browser acceptance remain pending where PHP/PostgreSQL are unavailable.
