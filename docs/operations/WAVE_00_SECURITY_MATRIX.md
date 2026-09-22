# Wave 0 Identity and Tenancy Security Matrix

| Control | Enforcement | Evidence |
|---|---|---|
| Invitation secrecy | 256-bit random token; SHA-256 stored; plaintext returned once | `InvitationService`, lifecycle test |
| Invitation replay | Row lock plus `PENDING` to `ACCEPTED` transition | `InvitationService::accept` |
| Recipient binding | Constant-time comparison to authenticated email/phone | `InvitationService::accept` |
| Role revocation | State, actor, timestamp and reason retained; role links and API tokens removed | `MembershipService` |
| Self-lockout | Administrators cannot revoke their own membership | Policy/service test |
| Organization lifecycle | Allow-listed state machine; actor/reason/notes history | `TenantLifecycleService` |
| MFA secret protection | Laravel encrypted cast; never serialized by the model | `MfaMethod` |
| MFA verification | RFC 6238/SHA-1, 30-second step, one-step clock tolerance | `TotpService` |
| Recovery codes | Individually generated and one-way password hashed | `AccountSecurityService` |
| Device revocation | Trust removed, revocation recorded, tokens invalidated | `AccountSecurityService` |
| Security telemetry | IP/user-agent are hashed; event metadata is structured | `security_events` |
| Mutation traceability | Hash-chained audit event for every lifecycle mutation | `AuditWriter` calls |
| Permission enforcement | Tenant-scoped fine-grained roles; explicit system-administrator bypass | `RequirePermission` |

Runtime penetration testing, dependency audit, MFA login enforcement, rate-limit verification and browser security-header verification remain acceptance blockers.
