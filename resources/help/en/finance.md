# Finance guide

Finance users work in the insurer portal (**/insurer**), the broker portal (**/broker**, for a brokerage's finance) or the administration console (**/admin**). You see only your organisation's money. Most money movements need two people: the person who prepares cannot approve.

## Getting started {#getting-started}

- Sign in to your portal. **Reports** gives the finance reports your role allows; **EN / FR** switches language.
- Amounts are shown in FCFA (XAF). Each step is an action on the record and appears only when your role and the record status allow it.

## Premium payments {#payments}

- Customers pay by MTN Mobile Money or Orange Money; brokers can send a payment request from a proposal.
- In **/broker**: **Payment dashboard**, **Pending payments**, **Failed payments** (**Retry payment**) and **Duplicate payments** (**Request refund**).
- In **/admin**: **Payment requests** lists every payment attempt and can **initiate** one.

## Commissions and statements {#commissions}

1. **Commission receivables**: commissions are recorded (**Accrue commission**), become earned (**Mark earned**) and payable (**Make payable**) as the premium is collected. **Approve commission**, **Adjust amount**, **Dispute** / **Resolve dispute**; **Claw back** or **Reverse** when a policy is cancelled.
2. **Partner Statements**: **Generate statements** for a period, **Approve statement**, **Publish statement**. The partner can raise a dispute or an adjustment, which is approved or rejected.
3. **Request payout** on a published statement creates a **Partner Payout Request**: **Approve payout**, **Send for payment**, **Confirm paid** (or **Record failure**); **Reverse payout** if needed.

## Settlements and bordereaux {#settlements}

- **Settlements** between brokers and insurers: **Prepare settlement (policies)** or a new settlement from obligations, **Calculate**, **Submit for review**. The approver uses **Approve settlement** or **Send back**. Then **Submit to bank** / **Send for payment**, **Confirm paid** (or **Record bank failure**), **Confirm settled**. **Reconcile** matches it with the bank; **Reverse settlement** undoes it.
- **Bordereaux**: brokers **Prepare bordereau**, **Approve bordereau** and **Submit to insurer**; the insurer uses **Record acknowledgement** or **Record rejection**.
- **Claim payments** are requested by the claims team and approved and paid through the same maker-checker controls.

## Reconciliation and ledger {#reconciliation}

- **/admin → Reconciliations** compares money received with money expected; the list shows matched lines and exceptions. The **exception centre** and **account statements** list what still needs attention.
- **Journal Records** (insurer) show the accounting entries, read only.
- **Cashier sessions** and **Exchange rates** are available to the roles that hold them.
- Health providers' **Provider settlement batches**: **Create settlement batch**, then **Pay settlement batch**.
