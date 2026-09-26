<?php

// Signed-in account shell (header, sidebar, shared JS strings). FR mirror: resources/lang/fr/account.php.
// Page copy lives in per-area files: account_buy.php, account_policies.php, account_claims.php, account_desk.php.
return [
    'motto' => 'A safer brighter Africa',
    'account_nav' => 'Account navigation',
    'sign_out' => 'Sign out',
    'nav' => ['products' => 'Products', 'compare' => 'Compare', 'support' => 'Support'],
    'side' => [
        'dashboard' => 'Dashboard', 'policies' => 'My Policies', 'quotes' => 'Quotes', 'claims' => 'Claims', 'payments' => 'Payments',
        'documents' => 'Documents', 'vehicles' => 'My Vehicles', 'customers' => 'Customers', 'desk' => 'Claims Desk',
        'profile' => 'My Profile', 'notifications' => 'Notifications', 'support' => 'Support',
    ],
    'help_t' => 'Need Help?', 'help_d' => 'Talk to our insurance experts.', 'help_btn' => 'Call Us',
    'js' => [
        'loading' => 'Loading…', 'empty' => 'Nothing here yet.', 'error' => 'Something went wrong. Please try again.', 'retry' => 'Try again',
        'wait' => 'Please wait…', 'forbidden' => 'Your account does not have access to this.', 'signed_out' => 'Your session has ended. Please sign in again.',
        'roles' => ['customer' => 'Individual Customer', 'agent' => 'Agent / Broker', 'officer' => 'Claims Officer'],
        'status' => [
            'ACTIVE' => 'Active', 'PENDING' => 'Pending', 'PAID' => 'Paid', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'SETTLED' => 'Settled',
            'SUBMITTED' => 'Submitted', 'UNDER_REVIEW' => 'Under review', 'UNDER_ASSESSMENT' => 'Under assessment', 'IN_PROGRESS' => 'In progress',
            'DRAFT' => 'Draft', 'EXPIRED' => 'Expired', 'CANCELLED' => 'Cancelled', 'FAILED' => 'Failed', 'COMPLETED' => 'Completed', 'ISSUED' => 'Issued',
        ],
    ],
];
