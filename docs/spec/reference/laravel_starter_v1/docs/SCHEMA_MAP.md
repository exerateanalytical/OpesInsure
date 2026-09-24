# Schema Map

| Domain | Core tables |
|---|---|
| Identity/Tenancy | tenants, users, roles, permissions, user_roles |
| Party/Organization | parties, persons, organizations, branches, departments, relationships, ownership_interests |
| Authority | authority_profiles, authority_limits, authority_profile_assignments |
| Compliance/MDM | kyc_cases, screening_checks, master_data_* |
| Risk Assets | vehicles, properties |
| Regulatory | regulatory_regimes, insurance_branches, insurer_authorizations |
| Product | insurance_products, product_versions, product_plans, coverages, rating_rules, eligibility_rules, underwriting_rules |
| Distribution | insurer_broker_agreements, agreement_product_authorizations |
| Quote/UW | quotes, quote_risks, pricing_snapshots, proposals, underwriting_cases |
| Policy | policies, policy_versions, policy_parties, policy_risks, policy_coverages, policy_limits, endorsements, renewals |
| Finance | financial_obligations, payments, payment_allocations, refunds, commissions, journals |
| Claims | claims, claim_parties, claim_coverage_assessments, claim_reserves, claim_decisions, recoveries |
| Provider | provider_profiles, provider_networks, provider_contracts, preauthorizations, provider_claims |
| Reinsurance | reinsurance_treaties, risk_cessions, facultative/co-insurance extension tables |
| Documents | files, document_types, templates, template_versions, documents |
| Work Management | cases, tasks, correspondences |
| Platform Controls | regulatory_rules, audit_events, outbox_events |
