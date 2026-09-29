<?php

/*
 * In-app / push / SMS notification copy, keyed by the stable code stored on
 * user_notifications.code (see App\Application\Notifications\NotificationCatalog).
 * The English text is also what the row's title/body columns keep, so it must
 * stay in step with the producers. Placeholders use Laravel's :param syntax;
 * :Param is the same value with its first letter capitalised.
 */
return [
    // Values used when a producer has no value for a placeholder.
    '_defaults' => [
        'product' => 'your cover',
        'device' => 'a new device',
    ],
    // Service-request types (policy_transactions.transaction_type), lower-case inside a sentence.
    '_service_types' => [
        'ENDORSEMENT' => 'endorsement',
        'CANCELLATION_REVIEW' => 'cancellation review',
        'ADDRESS_CHANGE' => 'address change',
        'VEHICLE_CHANGE' => 'vehicle change',
        'BENEFICIARY_CHANGE' => 'beneficiary change',
        'DOCUMENT_REISSUE' => 'document reissue',
    ],

    // Quotes
    'quote_offers_ready_one' => ['title' => 'Your quotes are ready', 'body' => '1 offer is ready to review.'],
    'quote_offers_ready' => ['title' => 'Your quotes are ready', 'body' => ':count offers from licensed insurers are ready to compare.'],
    'quote_referred' => ['title' => 'Your quote needs a closer look', 'body' => 'No instant offer matched your details. An underwriter will review your request.'],
    'quote_sent_to_insurer' => ['title' => 'Your request was sent to an insurer', 'body' => 'An insurer is preparing a personalised offer. We will tell you as soon as it arrives.'],
    'quote_new_offer' => ['title' => 'A new insurer offer is ready', 'body' => 'An insurer has sent you a personalised offer to review.'],
    'quote_declined' => ['title' => 'The insurer could not offer cover', 'body' => 'The insurer declined to quote this risk. Your adviser will suggest alternatives.'],
    'carrier_quote_request' => ['title' => 'New manual quotation request', 'body' => 'Quote request :request is waiting for your offer.'],

    // Proposals
    'proposal_under_review' => ['title' => 'Proposal under review', 'body' => 'Your proposal has been submitted and is being reviewed by the insurer.'],
    'proposal_approved' => ['title' => 'Proposal approved', 'body' => 'Your proposal was approved. Complete payment to activate your cover.'],
    'proposal_counteroffer' => ['title' => 'Counter-offer available', 'body' => 'The insurer has proposed revised terms. Review and accept or decline the counter-offer.'],
    'proposal_declined' => ['title' => 'Proposal declined', 'body' => 'The insurer declined your proposal. You can compare other offers.'],
    'proposal_information_needed' => ['title' => 'More information needed', 'body' => 'The underwriter needs more information about your proposal. Open it to respond.'],

    // Payments and issuance
    'payment_failed' => ['title' => 'Payment failed', 'body' => 'Your payment did not go through. No money was taken; you can retry from the payment screen.'],
    'payment_expired' => ['title' => 'Payment failed', 'body' => 'Your payment request expired before it was approved. You can try again from the payment screen.'],
    'payment_issuance_in_progress' => ['title' => 'Payment received — issuance in progress', 'body' => "We received your payment for :product. The insurer is issuing your policy; we'll notify you as soon as you're covered."],
    'payment_issuance_delayed' => ['title' => 'Payment received — policy issuance delayed', 'body' => 'We received your payment for :product and it is safe. Issuing your policy is taking longer than expected; our team is handling it and will keep you informed.'],
    'policy_issued' => ['title' => "You're covered", 'body' => ':Product policy :policy is active from :starts. Your certificate is in your wallet.'],
    'policy_active' => ['title' => 'Your policy is active', 'body' => 'Policy :policy has been issued. Your certificate is ready in your wallet.'],
    'policy_issuance_failed' => ['title' => 'Issuance could not be completed', 'body' => 'The insurer could not issue your policy. Our team will contact you about next steps, including a refund if applicable.'],

    // Policy servicing
    'policy_cancellation_notice' => ['title' => 'Notice of cancellation', 'body' => 'Policy :policy is due to be cancelled with effect from :effective. Estimated refund: :refund :currency (minor units).'],
    'policy_cancellation_requested' => ['title' => 'Cancellation request received', 'body' => 'Policy :policy is due to be cancelled with effect from :effective. Estimated refund: :refund :currency (minor units).'],
    'policy_cancelled' => ['title' => 'Policy cancelled', 'body' => 'Policy :policy is cancelled with effect from :effective.'],
    'policy_cancelled_refund' => ['title' => 'Policy cancelled', 'body' => 'Policy :policy is cancelled with effect from :effective. A refund of :refund :currency (minor units) has been requested.'],
    'service_request_received' => ['title' => 'Request received', 'body' => 'We received your :service request for policy :policy. Our team will review it within 2 business days.'],
    'document_new_one' => ['title' => 'New document available', 'body' => '1 new document for policy :policy (:label). Open your policy to view and download.'],
    'document_new_many' => ['title' => 'New documents available', 'body' => ':count new documents for policy :policy (:label). Open your policy to view and download.'],
    'renewal_ends_tomorrow' => ['title' => 'Your cover ends tomorrow', 'body' => ':Product policy :policy expires tomorrow (:ends). Renew now to stay covered.'],
    'renewal_ends_in_days' => ['title' => 'Your cover ends in :days days', 'body' => ':Product policy :policy expires in :days days (:ends). Renew now to stay covered.'],

    // Claims
    'claim_submitted' => ['title' => 'Claim received', 'body' => 'We received claim :claim. We will acknowledge it shortly.'],
    'claim_acknowledged' => ['title' => 'Claim acknowledged', 'body' => 'Claim :claim has been acknowledged and assigned to a handler.'],
    'claim_evidence_pending' => ['title' => 'Documents requested for your claim', 'body' => 'The insurer needs more documents for claim :claim. Open the claim to see what to upload.'],
    'claim_assessment' => ['title' => 'Claim under assessment', 'body' => 'Claim :claim is being assessed.'],
    'claim_carrier_review' => ['title' => 'Claim with the insurer', 'body' => 'Claim :claim is with the insurer for a decision.'],
    'claim_approved' => ['title' => 'Claim approved', 'body' => 'Claim :claim has been approved. Settlement is being prepared.'],
    'claim_partially_approved' => ['title' => 'Claim partially approved', 'body' => 'Claim :claim has been partially approved. Open it to review the settlement.'],
    'claim_declined' => ['title' => 'Claim declined', 'body' => 'Claim :claim was declined. Open it to see the reason and your appeal options.'],
    'claim_paid' => ['title' => 'Claim paid', 'body' => 'The settlement for claim :claim has been paid.'],
    'claim_disputed' => ['title' => 'Appeal registered', 'body' => 'Your appeal on claim :claim is registered and will be reviewed.'],
    'claim_closed' => ['title' => 'Claim closed', 'body' => 'Claim :claim has been closed.'],
    'claim_reopened' => ['title' => 'Claim reopened', 'body' => 'Claim :claim has been reopened.'],
    'claim_status_changed' => ['title' => 'Claim update', 'body' => 'Claim :claim is now :status.'],
    'claim_appeal_received' => ['title' => 'Appeal received', 'body' => 'Your appeal on claim :claim was received (ref :reference). A claims officer will respond within 5 business days.'],
    'claim_large_loss' => ['title' => 'Large loss reported', 'body' => 'Claim :claim has a loss of :amount :currency (minor units), at or above the large-loss threshold.'],

    // Account security
    'security_new_sign_in' => ['title' => 'New sign-in to your account', 'body' => "Your account was signed in on :device. If this wasn't you, change your password and sign out of all devices."],
    'security_password_changed' => ['title' => 'Your password was changed', 'body' => "Your OpesInsure password was just changed. If this wasn't you, reset it now and sign out of all devices."],
    'security_alert_new_device' => ['title' => 'New device signed in', 'body' => 'Your account was just used on a new device. If this was not you, sign out everywhere and change your password.'],
    'security_alert_password_changed' => ['title' => 'Password changed', 'body' => 'Your password was changed. If this was not you, contact support right away.'],
    'security_alert_identity_changed' => ['title' => 'Account details changed', 'body' => 'Your sign-in or contact details were changed. If this was not you, contact support right away.'],
    'security_alert_signed_out_everywhere' => ['title' => 'Signed out everywhere', 'body' => 'All sessions on all devices were signed out.'],
    'security_alert_repeated_failed_sign_ins' => ['title' => 'Failed sign-in attempts', 'body' => 'Several failed sign-in attempts were made on your account.'],
    'security_alert_integrity_failure' => ['title' => 'Device integrity check failed', 'body' => 'A device using your account failed the integrity check. Sensitive actions are limited on it.'],
    'security_alert_payout_destination_changed' => ['title' => 'Payout destination changed', 'body' => 'The account your payouts are sent to was changed. If this was not you, contact support right away.'],
    'security_alert_access_suspended' => ['title' => 'Access suspended', 'body' => 'Your organisation suspended your access.'],
    'security_alert_reauth_required' => ['title' => 'Sign in again', 'body' => 'Your organisation asked you to sign in again on every device.'],

    // S8 launch coverage (LaunchNotificationRouter)
    'complaint_received' => ['title' => 'Complaint received', 'body' => 'We registered your complaint :reference. We will acknowledge it within 2 business days.'],
    'complaint_acknowledged' => ['title' => 'Complaint acknowledged', 'body' => 'Your complaint :reference has been acknowledged and is being investigated.'],
    'complaint_information_needed' => ['title' => 'Information needed on your complaint', 'body' => 'We need more information to handle complaint :reference. Please contact us or reply in the app.'],
    'complaint_resolution' => ['title' => 'Complaint response sent', 'body' => 'We have sent our response to complaint :reference. If you are not satisfied you may escalate it.'],
    'complaint_escalated' => ['title' => 'Complaint escalated', 'body' => 'Complaint :reference has been escalated for further review.'],
    'complaint_closed' => ['title' => 'Complaint closed', 'body' => 'Complaint :reference is now closed.'],
    'kyc_approved' => ['title' => 'Identity verified', 'body' => 'Your identity verification has been approved.'],
    'kyc_rejected' => ['title' => 'Identity verification not approved', 'body' => 'Your identity verification could not be approved. Open your profile to see what to do next.'],
    'kyc_information_requested' => ['title' => 'More information needed for verification', 'body' => 'We need more information to verify your identity. Open your profile to respond.'],
    'kyc_remediation_requested' => ['title' => 'Please update your identity documents', 'body' => 'Your identity documents need to be updated. Open your profile to submit new ones.'],
    'kyc_expired' => ['title' => 'Identity verification expired', 'body' => 'Your identity verification has expired. Open your profile to renew it.'],
    'agent_payment_requested' => ['title' => 'Payment requested by your adviser', 'body' => 'Your adviser has asked you to complete payment for quote :reference. Open the app to pay.'],
    'proposal_conditional_offer' => ['title' => 'Approved with conditions', 'body' => 'Your proposal was approved with conditions. Review the conditions before you pay.'],
    'endorsement_issued' => ['title' => 'Policy change confirmed', 'body' => 'The change to policy :policy has been issued. Your updated documents are in your wallet.'],
    'claim_expert_assigned' => ['title' => 'Expert appointed on your claim', 'body' => 'An expert has been appointed on claim :claim and will contact you to arrange an inspection.'],
    'claim_inspection_scheduled' => ['title' => 'Inspection scheduled', 'body' => 'An inspection has been scheduled for claim :claim. Open the claim to see the date and place.'],
    'expert_assignment_new' => ['title' => 'New expert assignment', 'body' => 'You have been appointed on claim :claim. Accept or decline the assignment.'],
    'settlement_offered' => ['title' => 'Settlement offer ready', 'body' => 'A settlement offer is ready for claim :claim. Open the claim to accept or dispute it.'],
    'settlement_discharge_requested' => ['title' => 'Discharge to sign', 'body' => 'Please sign the discharge for claim :claim so the payment can be made.'],
    'refund_requested' => ['title' => 'Refund requested', 'body' => 'A refund :reference has been requested on your payment. We will tell you once it is approved.'],
    'refund_approved' => ['title' => 'Refund approved', 'body' => 'Refund :reference has been approved and will be paid to your original payment method.'],
    'commission_statement_ready' => ['title' => 'Commission statement ready', 'body' => 'Commission statement :reference has been approved and is ready to view.'],
    'commission_statement_dispute_resolved' => ['title' => 'Statement dispute resolved', 'body' => 'The dispute on commission statement :reference has been resolved.'],
    'payout_approved' => ['title' => 'Payout approved', 'body' => 'Payout :reference has been approved.'],
    'payout_paid' => ['title' => 'Payout sent', 'body' => 'Payout :reference has been paid.'],
    'payout_reversed' => ['title' => 'Payout reversed', 'body' => 'Payout :reference was reversed. Contact finance for details.'],
    'bordereau_approved' => ['title' => 'Bordereau approved', 'body' => 'Bordereau :reference has been approved.'],
    'bordereau_acknowledged' => ['title' => 'Bordereau accepted by the insurer', 'body' => 'The insurer acknowledged bordereau :reference.'],
    'bordereau_rejected' => ['title' => 'Bordereau rejected by the insurer', 'body' => 'The insurer rejected bordereau :reference. Open it to see the reason.'],
    'staff_invitation' => ['title' => 'You are invited to OpesInsure', 'body' => 'You have been invited to join a team on OpesInsure. Invitation code: :code (valid :hours hours).'],
    'document_scan_clean' => ['title' => 'Document accepted', 'body' => 'Your uploaded document passed the security check.'],
    'document_scan_infected' => ['title' => 'Document rejected', 'body' => 'Your uploaded document failed the security check. Please upload a clean copy.'],
];
