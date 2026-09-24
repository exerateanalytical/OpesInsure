# Build Order

1. Engineering foundation and environment
2. Identity / tenancy / RBAC / delegated authority
3. Organization and Master Data Management
4. Party/customer/relationships/UBO/KYC
5. Regulatory branch classification and insurer authorization
6. Product/version/coverage/question/rating/UW configuration
7. Distribution agreements and commissions
8. Quote/proposal/UW referrals
9. Policy temporal model / endorsements / renewals / cancellations
10. Document engine
11. Billing/payments/allocation/reconciliation/refunds
12. Commission/settlement/accounting
13. Claims / coverage-at-loss / limits / reserves / decisions
14. Provider network / eligibility / preauth / provider claims
15. Reinsurance / co-insurance / recoveries
16. AML/compliance/case/SLA/correspondence
17. Catastrophe/accumulation/regulatory reporting
18. API/developer portal and integrations
19. Migration/demo/UAT/security/performance/DR

## Gates
Every state mutation: AuthN → AuthZ → scope → state guard → authority → maker/checker → transaction → event/outbox → audit → response.
