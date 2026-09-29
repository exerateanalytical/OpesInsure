# Broker staff guide

The broker portal (**/broker**) is where your brokerage sells, services policies and follows claims for its clients. As broker staff (producer) you see the records assigned to you; your supervisor sees the team's and the broker administrator the whole company's. You never see another brokerage's clients.

## Signing in and your dashboard {#getting-started}

- Sign in at **/broker/login** (password reset on the same page). Your profile is in the user menu; **EN / FR** switches language.
- The **Dashboard** shows your work at a glance. **Customer dashboard** and **Claims dashboard** give more detail.
- **Global search** finds a customer, quote, policy or claim in your scope.
- Changes are made through named actions in the **… actions** menus of each record, never by editing a record directly. If an action is missing, your role does not grant it or the record is not in the right status.

## Customers and leads {#customers}

- **My customers** (**/broker/customers**) lists your clients. **New customer** registers one; on a row, **New quote**, **Open KYC file** and **Submit KYC file**.
- **Leads** is your prospect pipeline: **New lead**, **Log activity**, **Move lead**. Open a lead to see its history.
- **Customer activity** shows a client's timeline (choose the customer first).
- The **Partner workspace** menu links to your account area (**/account**) for **My clients**, **New client**, **New quote**, **Leads** and **Reports** – the same screens as the mobile app.

## Quotes {#quotes}

1. Start a quote from **My customers → New quote** or **Partner workspace → New quote**.
2. Open it in **Quotes** (**/broker/quotes**). The detail page compares the insurers' offers.
3. Use **Quote actions**: **Re-rate quote**, **Send to client**, **Request insurer quote** (for risks that need the insurer's pricing), **Record insurer quote on behalf**, **Request premium override**, **Generate quotation**, **Save offer comparison**.
4. When the client accepts, choose **Convert to proposal**. If the client refuses, **Record client decline**; **Cancel quote** closes it.

Useful queues: **Quote dashboard**, **Exceptional quotes**, **Product eligibility** (**Check eligibility** before quoting).

## Proposals and payment {#proposals}

1. Open the proposal in **Proposals**. Use **Proposal actions**: **Enter disclosure answers**, **Attest disclosures**, **Record declarations**, **Set cover terms**, **Attach document**.
2. **Submit proposal** to the insurer. If the insurer asks for information, use **Answer information request**.
3. **Request premium payment** sends a mobile-money payment request to the client; **Download receipt** once paid.
4. When the proposal is paid (status PAYMENT_PENDING cleared), **Request issuance**.

Queues: **Proposal completeness**, **Information requests**, **Conditional offers**, **Declined proposals**, **Pending payments**, **Failed payments** (**Retry payment**).

## Policies and servicing {#policies}

- **Policies** lists the policies of your book. Open one for its documents and history.
- **Policy actions**: **Request endorsement**, **Request cancellation**. **Policy servicing**: **Request reinstatement**, **Transfer policy**, **Portability export**, **Request recovery**. **Servicing request** records a client request.
- **Issue certificate** is available on active policies when your role allows it.
- Queues: **Policy dashboard**, **Cancellation queue**, **Failed issuance**, **Documents pending**, **Sticker allocation**.

## Renewals {#renewals}

- **Renewal Cases** lists policies coming up for renewal in your book.
- **Re-quote renewal** (status DUE or CONTACTED) prepares the new terms; **Link successor policy** (status QUOTED) links the renewed policy.
- **Lapsed policies** lists policies that were not renewed.

## Claims {#claims}

- **Claims** lists your clients' claims. **Declare a claim for a customer** or **Report a claim for a client** to open one; **Check coverage at loss** first if unsure.
- Open a claim to follow it through its tabs (Evidence review, Experts, Settlements…) and add evidence. The insurer assesses and decides.
- Queues under **Claims operations** (evidence review, settlement preparation, appeals, reopening) show what the claims process expects from you.
