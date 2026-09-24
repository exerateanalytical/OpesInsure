# OpesInsure master workflow map — full owner specification (2026-09-24)

C = Customer, A = Agent, B = Broker. Arrows are the owner's step sequences.

1 Onboarding. C: Open App/Web → Register → Verify Phone/Email → Create Profile → Provide Identification → Accept Terms/Consent → Complete KYC → Account Activated → Dashboard; then add address, beneficiaries, vehicles/assets, company details if corporate, manage ID documents, communication preferences. A: Create/Search Customer → Check Duplicate → Capture Info → Upload KYC → Verification → Submit KYC → Correct Rejected Info → Customer Activated (no duplicates to make a sale). B: Customer Queue → Review New Customer/KYC → Resolve Duplicates → Approve Internal Requirements → Assign Agent/Branch → Added to Broker Portfolio; monitors incomplete KYC, expired IDs, duplicates, corporate documentation, assigned relationship manager.

2 Lead. C: Explore Insurance → Request Quote / Request Call → Lead Created. A: New Lead → Contact → Qualify → Capture Need → Select Product → Create Quote; states New → Contacted → Qualified → Quote Started → Quote Sent → Won / Lost / Dormant. B: Lead Received → Route to Branch/Agent → Monitor SLA → Review Pipeline → Reassign → Conversion/Loss Reporting; sees source, owner, product interest, expected premium, age, next activity, conversion status.

3 Product discovery. C: Dashboard/Marketplace → Category → Product List → Product Details → Coverage → Exclusions → Requirements → Start Quote (Motor, Health, Travel, Property, Personal Accident, Business, Marine/Cargo). A: Customer Profile → Needs Assessment → Search Products → Compare → Explain Coverage → Start Quote. B: Product Catalogue → Insurer Products → Availability → Agent Permissions → Product Rules → Monitor Sales; broker configures which products its agents distribute.

4 Quotation. C: Select Product → Questions → Risk Details → Add Assets/Persons → Select Coverage → Upload Info → Calculate → Review → Save / Accept / Request Help; states Draft → Calculated → Generated → Sent → Viewed → Accepted / Declined / Expired. A: Customer → New Quote → Select Insurer/Product → Capture Risk → Select Guarantees → Run Rating → Review Premium → Explain → Send Customer → Follow Up; can save draft, duplicate quote, compare, request broker assistance, submit exceptional case to underwriting. B: Oversight → Review High-Value/Exceptional → Approve Overrides if Authorized → Monitor Conversion → Track Lost Quotes; sees volume, value, accepted, declined, expiration, conversion by agent/product/insurer.

5 Comparison. C: Multiple Quotes → Compare Premium → Coverage → Deductibles → Limits → Exclusions → Insurer → Select Offer (compare facts, no misleading ranking). A: Generate Multiple Quotes → Explain Differences → Customer Chooses → Record Selection. B: Monitor Comparison Usage → Ensure Product Data Accuracy → Audit Manual Overrides.

6 Proposal. C: Accept Quote → Complete Proposal → Confirm Personal/Risk Data → Upload Documents → Declare Accurate → Consent → Sign/Confirm → Submit; states Draft → Submitted → Under Review → Additional Information Required → Resubmitted → Approved / Declined. A: Accepted Quote → Convert → Complete Details → Upload Docs → Verify Declaration → Submit; if returned: Contact Customer → Collect → Resubmit. B: Proposal Queue → Validate Completeness → Compliance Check → Route to Insurer/Underwriting → Monitor Decision.

7 Underwriting. C: Submitted → Await Decision → Info Request → Provide Documents/Answers → Receive Decision (accepted, with conditions, adjusted premium, adjusted coverage, declined). A: Track → Respond to Query → Contact Customer → Upload Evidence → Communicate Decision. B: UW Queue → Monitor Referrals → Coordinate with Insurer → Review Special Terms → Approve Internal Exceptions → Communicate Decision.

8 Premium acceptance. C: Accepted → Review Final Premium → Review Coverage → Accept → Payment. A: Review Final Terms → Explain Changes → Obtain Acceptance → Initiate Payment. B: Verify Approved Terms → Ensure No Unauthorized Changes → Release for Payment.

9 Payment. C: Choose Method (MTN MoMo / Orange Money / Bank / Card / Authorized Cash) → Confirm → Processing → Success (Paid → Receipt → Issuance) / Failure (Reason → Retry / Change Method). A: Open Proposal → Initiate Payment → Send Request → Monitor → Assist Retry → Confirm Receipt; cannot mark unpaid as paid without controlled authorization. B: Monitoring → Match → Resolve Unmatched → Reconcile → Approve Exceptional Manual Entries. States Initiated → Pending → Successful → Failed → Reversed → Refunded.

10 Issuance. C: Complete → Policy Issued → Wallet → Download Contract → Download Attestation → Notifications. A: Track → Verify Details → Deliver Documents → Explain Dates/Coverage → Follow-Up. B: Issuance Queue → Awaiting Issuance → Resolve Carrier Errors → Verify Document Generation → Activated. Policy: Pending Issue → Active → Amended → Suspended → Cancelled / Expired / Renewed.

11 Policy management. C sees number, insurer, product, insured items/persons, premium, coverage, exclusions, dates, documents, payments, claims, renewal; actions View, Download, Request Change, Renew, Claim, Contact Agent. A: Customer → Policy → Review → Service → Endorsement / Renewal / Claim. B: Portfolio → Search → Monitor Status → Exceptions → Service Requests → Administration.

12 Documents. C: Policy → Documents → View / Download / Verify / Share (quotation, proposal, policy, schedule, terms, receipt, attestation, endorsement, renewal notice, claim documents). A: Generate/Request Authorized Document → Deliver. B: Document Queue → Validate Generation → Reissue if Permitted → Revoke Invalid → Audit. Every document traceable to policy/version.

13 Motor attestation/sticker. C: Motor Active → Attestation → Sticker Assignment → Collection/Delivery → Confirmation. A: Eligible Policy → Request/Assign Sticker → Scan/Enter Serial → Hand Over → Capture Receipt. B: Receive Stock → Record Batch → Allocate to Branch → Allocate to Agent → Assign to Policy → Reconcile. States Received → In Stock → Allocated → Assigned → Issued → Active; exceptions Damaged / Lost / Void / Returned.

14 Endorsement/avenant (address, vehicle, driver, beneficiary, coverage, insured value). C: Policy → Request Change → Select → Details → Evidence → Submit → Price Difference → Pay/Refund → Receive Endorsement. A: New Endorsement → Capture → Validate Docs → Calculate Difference → Submit → Follow Approval → Deliver Avenant. B: Queue → Review → Refer to Insurer → Approve → Recalculate → Issue Avenant. States Draft → Submitted → Under Review → Approved → Financial Adjustment → Issued.

15 Renewal. C: Notification → Review Policy → Update Details → Renewal Quote → Accept → Pay → New Period → Documents. A: Renewal Queue → Contact → Review Risk Changes → Generate Renewal → Follow Up → Collect Payment → Confirm Issuance. B: 30/60/90-Day Dashboard → Assign → Generate Offers → Track Contacts → Monitor Conversion → Escalate High-Value. States Upcoming → Contacted → Quoted → Accepted → Paid → Renewed; or Declined / Lapsed / Lost.

16 Cancellation. C: Request → Reason → Evidence → Submit → Financial Consequences → Confirmation. A: Receive → Explain Consequences → Capture → Submit to Broker. B: Queue → Validate Eligibility → Calculate Refund/Outstanding → Approval → Cancel → Notice. States Requested → Under Review → Approved → Financial Adjustment → Cancelled.

17 Suspension/reinstatement. C: Suspended → View Reason → Resolve → Request Reinstatement. A: Review Suspended → Contact → Collect Info/Payment → Request Reinstatement. B: Queue → Validate Cause → Track → Approve/Forward → Restore.

18 FNOL. C: Policy → Make a Claim → Incident Type → Date/Time/Location → Describe → Affected Property/Person → Initial Evidence → Submit; system gives reference, next steps, required evidence, status. A: Customer → Policy → Start Claim → Capture FNOL → Evidence → Verify Contacts → Submit. B: New Claims Queue → Validate Policy → Incident Date → Coverage → Assign Handler → Forward to Insurer.

19 Evidence. C: Claim → Required Documents → Upload Photo/Video/Police Report/Estimate/Medical/Other → Submit; sees received, rejected, more info required. A: Missing Documents → Contact → Upload → Submit. B: Evidence Review → Validate → Classify → Request Missing → Evidence Timeline.

20 Assessment. C tracks Submitted → Reviewing → Assessment Scheduled → Assessment Complete. A: Track → Schedule Interaction → Coordinate Inspection → Update Customer. B: Assign Expert/Adjuster → Schedule Inspection → Receive Assessment → Review Report → Refer to Insurer.

21 Investigation (only where necessary). C: Clarification Request → Respond → Evidence. A: Assist Response, never alter evidence, forward. B: Flag → Investigation Case → Assign Investigator → Gather Evidence → Findings → Continue Decision. Platform flags, never auto-accuses.

22 Decision. C receives approved / partially approved / additional review / rejected with reasons. A: Receive → Explain → Next Step. B: Review → Ensure Documentation → Communicate → Record.

23 Settlement. C: Approved → Review Settlement → Accept Discharge → Payment Details → Processed → Receipt. A: Monitor → Assist → Confirm Receipt. B: Settlement Approval → Payment Instruction → Monitor Insurer Payment → Reconcile → Close. States Approved → Payment Pending → Processing → Paid → Settled → Closed.

24 Rejection/appeal. C: Rejected → Reason → Appeal/Dispute → Info → Submit. A: Explain → Assist → Upload Evidence. B: Appeal Queue → Review → Forward to Insurer → Track → Communicate.

25 Reopening. C: Closed → Request Reopen → Reason → Evidence. A: Review → Submit. B: Validate Grounds → Approve/Refer → Reopen.

26 Refund (cancellation, overpayment, duplicate, endorsement reduction, failed issuance). C: Eligible → Confirm Destination → Track → Confirmation. A: Explain → Verify Details → Monitor. B: Request → Calculate → Approve → Payment → Reconcile.

27 Commission. A: Policy Paid → Accrued → Earned → Statement → Approved → Paid; sees policy, premium, rate, gross, deductions, reversals, net, settlement status. B: Calculation → Validate Carrier Terms → Broker Commission → Agent Allocation → Approval → Statement → Settlement. Reversal: Cancellation/Refund → Recalculated → Reversal Posted.

28 Broker-to-insurer settlement. A view-only. B: Premium Collected → Carrier Payable → Commission as Contracted → Batch → Review → Approve → Pay Carrier → Reconcile → Close. States Open → Calculated → Awaiting Approval → Approved → Processing → Settled → Reconciled.

29 Reconciliation. C sees correct status. A can flag "Customer Paid but Policy Shows Unpaid". B: Import Provider/Bank Transactions → Auto Match → Review Unmatched → Manual Match with Approval → Resolve Difference → Reconcile. Exceptions duplicate, short, over, unidentified payer, reversed.

30 Service request. C: Support → Issue → Ticket → Message → Evidence → Resolution → Close/Rate. A: Resolve if Permitted → Escalate to Broker. B: Queue → Assign → Investigate → Resolve → Escalate to Insurer/Technical → Close.

31 Complaint. C: Submit → Category → Description → Evidence → Confirmation → Track → Resolution → Escalate/Appeal. A assists, never suppresses/deletes. B: Register → Categorize → Assign → Investigate → Respond → Corrective Action → Close.

32 Communication. C receives quote, payment reminder, policy issued, expiry, claim updates, settlement, document requests; Open Notification → Related Record. A: Send Approved Message → Log → Schedule Follow-Up. B: Create Template → Approve → Target Audience → Send → Track Delivery/Failure.

33 Tasks. C may receive requested actions. A: Create Task → Link Customer/Quote/Policy/Claim → Due Date → Complete / Reschedule / Escalate. B: Assign → Monitor SLA → Reassign → Escalate Overdue.

34 Document expiry. C: Expiry Alert → Upload Replacement → Submit. A: Portfolio Alert → Contact → Capture Replacement. B: Compliance Dashboard → Expiring Documents → Review → Approve.

35 KYC remediation. C: Rejected → Required Correction → Edit → Resubmit. A: Assist → Correct → Upload. B: Review Queue → Accept / Reject / Clarify.

36 Corporate. C/representative: Create Organization → Company Details → Authorized Representatives → Registration Documents → Employees/Assets/Vehicles → Corporate Quote. A: Corporate Prospect → Needs → Risk Data → Multiple Quotes → Present Terms → Proposal. B: Corporate Account → Account Manager → Insurer Negotiation → Quotes → Portfolio → Renewals → Claims → Statements.

37 Vehicles. C: Profile → Vehicles → Add → Registration/VIN → Documents → Use in Quote. A: Verify → Correct Authorized Fields → Link to Quote/Policy. B: Vehicle Search → Duplicate Check → Policy History → Claim History (where allowed).

38 Beneficiaries. C: Add/Edit → Relationship → Identification → Allocation → Submit. A: Assist → Verify. B: Review → Approve through endorsement where necessary.

39 Notification-to-action. No dead messages; e.g. Expires in 14 days → Policy → Renewal Quote → Accept → Pay → Renew; Police report needed → Claim → Missing Documents → Upload → Submit. All three roles.

40 Portfolio transfer. B: Agent Leaves → Select Portfolio → Replacement Agent → Review Customers/Policies/Tasks → Transfer → Notify → Audit; old agent loses access; customer unaffected.

41 Agent suspension. B: Trigger → Suspend → Prevent New Business → Preserve History → Reassign Active Cases; never destroys customer records.

42 Maker/checker. Creator → Supervisor Reviews → Approve/Reject → Execute; for premium overrides, refunds, manual payments, settlements, commission adjustments, cancellation, document reissue, policy exceptions.

43 Search. C: My Policies → Search. A: Search Customer/Policy/Vehicle/Phone → Authorized Record. B: Global Search across policy, customer, quote, claim, payment, vehicle, document; permission-scoped.

44 Public verification. C shares QR/code; A verifies before handover; B investigates failures. External: Scan QR → Service → Valid / Expired / Revoked → Minimal Info.

45 Expired policy. C: Expired → Renewal Prompt → Start Renewal. A: Expired Portfolio → Contact → Renewal/Requote. B: Lapsed Dashboard → Reassign → Track Recovery.

46 Failed issuance. C sees "Payment Successful → Issuance Processing", never a false failure. A: Exception → Track → Communicate. B: Paid-but-Not-Issued Queue → Diagnose Insurer/API/Document Error → Safe Retry → Escalate → Issue/Reimburse. Idempotent: one payment can never create two policies.

47 Failed payment. C: Failed → View Reason → Retry → Change Channel. A: Alert → Assist. B: Failure Dashboard → Provider Errors → Reconciliation.

48 Duplicate payment. C: Detected → Case → Resolution/Refund. A: Flag. B: Review → Verify → Refund/Credit → Reconcile.

49 Renewal paid, issuance failed. Accepted → Paid → Issue Failed → Exception Queue → Retry → Issue; unrecoverable → Manual Investigation → Refund/Alternative Issuance.

50 Customer 360. C: Dashboard → Profile → Policies → Payments → Claims → Documents → Communications. A: Search → 360 → Leads → Quotes → Policies → Renewals → Claims → Payments → Activities. B: 360 → Identity/KYC → Portfolio → Financial Position → Claims → Interactions → Documents → Audit.

Roles across the lifecycle: Customer initiates, informs, consents, pays, receives cover, requests service, tracks claims. Agent sells, assists, collects information, follows up, services portfolio, facilitates renewals and claims, earns commission. Broker governs the operation, controls workflows, manages insurers and agents, oversees compliance, reconciles money, handles exceptions, supervises claims, maintains the audit trail.
