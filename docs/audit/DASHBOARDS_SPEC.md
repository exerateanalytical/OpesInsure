# OpesInsure — 26 canonical dashboards (owner specification, 2026-09-24)

Rule: every dashboard answers what is happening, what needs attention, and what action can be taken. Every KPI card opens the exact filtered records it counts (e.g. "27 Policies Expiring" opens those 27). Common structure: KPI strip → attention/exception queue → trends → work queues → financial/operational breakdown → recent activity → upcoming deadlines → quick actions → alerts; role-based visibility.

1. Customer · 2. Customer Insurance Portfolio · 3. Customer Claims · 4. Customer Payments & Billing
5. Agent · 6. Agent Sales · 7. Agent Commission · 8. Agent Customer Portfolio
9. Broker Executive · 10. Broker Sales & Production · 11. Broker Customer · 12. Broker Renewal · 13. Broker Claims · 14. Broker Finance · 15. Branch Manager
16. Insurer/Carrier Executive · 17. Carrier Underwriting · 18. Carrier Policy Administration · 19. Claims Operations · 20. Claims Adjuster/Expert
21. Finance & Accounting · 22. Settlement & Reconciliation · 23. Compliance & Risk · 24. Platform Administrator · 25. Operations/System Health · 26. Regulatory/Audit

The full per-dashboard content list is the owner's message of 2026-09-24 (kept verbatim in the session); each item above must show those metrics with drill-down to records.

---

# Canonical dashboard framework (owner specification, 2026-09-24)

Every dashboard answers: What is happening? What requires attention? What is changing? What can I do next?

| # | Component | Requirement |
|---|---|---|
| 1 | Identity header | Name, tenant, branch/unit, role, reporting period, last refresh, global date filter, branch/region + insurer/product filters where permitted, export, customization |
| 2 | KPI strip | 4–8 role KPIs: value, comparison period, change, status, drill-down to the records behind the figure |
| 3 | Action required / exception centre | Items needing human attention with priority, age, owner, deadline/SLA, status, direct action |
| 4 | Work queue | Reference, entity, stage, assignment, created, aging, SLA, priority, next action; sort, filter, assign, safe bulk ops, open case |
| 5 | Trends & performance | Today, 7d, 30d, month, quarter, year, previous period, custom |
| 6 | Portfolio breakdown | By insurer, product, class, region, branch, agent, broker, customer type, risk class, channel, claim category, status; every chart drills to records |
| 7 | Financial position | Billed, collected, outstanding, overdue, refunds, insurer payable, commissions, settlements, unmatched, reconciliation differences, aged receivables — ledger-backed only |
| 8 | Deadlines & calendar | Renewals, expiries, instalments, inspections, claim reviews, regulatory deadlines, settlements, KYC/licence expiry, follow-ups, tasks; navigation + reminders |
| 9 | Recent activity | Business events with actor, timestamp, entity, action, resulting status |
| 10 | Quick actions | Role-specific, permission-controlled visibility and execution |
| 11 | Notifications & alerts | Severities: informational, action required, warning, critical, system/security; acknowledge, assign, navigate, resolve; critical persists until resolved |
| 12 | Communications | Recent/unanswered messages, callbacks, follow-ups, tickets, reminders, delivery failures; send approved SMS/email/push in-flow |
| 13 | Risk / compliance panel | Flags with evidence; human decides |
| 14 | Drill-down standard | KPI → filtered list → record → timeline → permitted action |
| 15 | Status semantics | Normal, Attention, Warning, Critical, Completed, Pending, Failed, Suspended, Expired, Informational — colour + icon + label |
| 16 | Role & permission awareness | Scoped by tenant, company, branch, region, portfolio, role, permissions, financial/claims access, sensitive-data rules |
| 17 | Data freshness | Each metric classed real-time / near-real-time / aggregated / period-based; show last refresh; authoritative records for money and critical status |
| 18 | States | Loading, empty, partial, integration failure, stale, no permission, not configured, unexpected error — never blank or endless spinner |
| 19 | Responsive | Desktop cockpit; tablet fewer columns; mobile reprioritized (KPIs, critical actions, queues, upcoming, activity, collapsible analytics) |
| 20 | Auditability | Every data-changing action audited: actor, entity, before/after, timestamp, tenant, approval, request/device context |

Visual order: Header → KPI strip → Action required → Work queue → Trends → Breakdown → Financial position → Deadlines → Recent activity → Alerts → Quick actions.
