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
];
