# Healthcare provider guide

The Provider Portal (**/provider**) is the web workspace of hospitals, clinics, pharmacies and laboratories working with insurers on OpesInsure. Each menu entry appears only if your role grants it (front desk, doctor, billing, pharmacy, laboratory, finance or provider administrator).

## Signing in and the dashboard {#getting-started}

- Sign in at **/provider/login**. Use **Forgot password** on the login page to reset your password.
- The **Provider Dashboard** shows summary cards for your facility (pending requests, claims, payments).
- Switch language with **EN / FR** in the top bar. Tables have an **Open** action on each row to see the detail.

## Checking eligibility {#eligibility}

1. Open **Eligibility Check**.
2. Choose a **search method**: health card number, health card QR scan, membership number, policy number, national ID or other identifier, or name + date of birth.
3. Enter the patient search, the service code and the service date, then press **Eligibility Check**.
4. The result shows whether the member is covered. From there you can go straight to **New Preauthorization**.

## Preauthorizations {#preauthorizations}

- **Preauthorization List** shows your requests and their status.
- **New Preauthorization**: fill in the request type, policy ID, member reference, facility, service code, quantity, unit price and clinical notes, then **Submit request**.
- If the insurer asks a question, open the request and answer in **Preauthorization Query Response**, then **Send**.
- **Cancel request** withdraws a request that is still open. Actions only appear when the request status allows them.

## Admissions and treatment episodes {#admissions}

- **Admissions List → New admission → Record admission** registers an in-patient stay.
- **Extension Request → Request extension** asks the insurer for more days (give the new end date and a reason).
- **Discharge** records the discharge date.
- **Treatment Episodes**: open an episode (**New treatment episode**), **Add service** for each act, **Close episode**, then **Generate claim** to turn the episode into a claim.

## Submitting claims {#claims}

1. Open **Claims List** and choose **New Provider Claim**.
2. Enter the provider code, facility, contract, policy ID, preauthorization ID (if any), member reference and invoice reference.
3. Add each service with **Add line** (service code, service date, quantity, unit price).
4. **Save draft claim** to finish later, or **Submit claim to insurer**.
5. If the insurer asks a question, reply in **Claim Query Response** and **Send**.

The insurer decides the claim; you follow its status in the list.

## Payments, reconciliation and disputes {#settlements}

- **Settlements**: open a settlement to see the claims paid and **Download statement**.
- **Reconciliation**: **Record payment received**, then **Allocate to claim** to match the money to your claims.
- **Disputes**: **Open a dispute** on a claim, a claim line, a settlement or a reconciliation (reason, amount, description) and **Submit dispute**. The insurer resolves it.
- **Provider Accounts** shows your balance with each insurer (read only).

## Contracts, documents and reports {#documents}

- **Contracts** lists your agreements with insurers; **Tariff Schedules** shows the agreed prices.
- **Documents**: filter by document type, **Download** or **Verify** a document. Documents are produced by the platform; there is no upload on this page.
- **Reports**: filter by facility, claim status and dates, then **Export CSV**.
- **Notifications** lists the messages sent to your facility.

## Administration {#administration}

For provider administrators:

- **User Management**: **Assign a user** a role and a facility scope (all facilities or assigned ones), and **Revoke** access.
- **Facility Management**: add departments to a facility.
- **Provider Profile**: your organisation's details.
- **Integration Settings**: the API base address and rules for connecting your hospital system.
- **Audit Log**: who did what in your workspace.
