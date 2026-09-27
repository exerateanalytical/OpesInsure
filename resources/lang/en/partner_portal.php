<?php

// Broker portal (/broker) labels added by the UI audit of 2026-09-27 (broker / agent web experience).
return [
    'columns' => [
        'policy' => 'Policy', 'amount' => 'Amount', 'vested' => 'Vested', 'paid' => 'Paid', 'status' => 'Status',
        'settlement_number' => 'Settlement number', 'period_start' => 'Period start', 'period_end' => 'Period end', 'net_amount' => 'Net amount',
        'bordereau_number' => 'Bordereau number', 'type' => 'Type', 'item_count' => 'Items', 'gross_premium' => 'Gross premium',
    ],
    'financial_operations' => 'Financial operations',
    'workspace' => [
        'group' => 'Partner workspace',
        'clients' => 'My clients',
        'new_quote' => 'New quote',
        'new_client' => 'New client',
        'leads' => 'Leads',
        'commissions' => 'Commissions & statements',
    ],
    // Staff / memberships list (shared MembershipResource: admin "Access & roles", portal "Staff").
    'staff' => [
        'model' => 'Access assignment', 'plural' => 'Access & roles', 'user' => 'User', 'organization' => 'Organisation', 'branch' => 'Branch', 'all_branches' => 'All branches',
        'role' => 'Primary role', 'status' => 'Status', 'revoke' => 'Revoke access', 'empty_t' => 'No access assignments', 'empty_d' => 'Invite a colleague or assign an existing user to an organisation.',
    ],
    'statuses' => ['ACTIVE' => 'Active', 'SUSPENDED' => 'Suspended', 'REVOKED' => 'Revoked'],
    'roles' => ['BROKER_ADMIN' => 'Broker administrator', 'BROKER_SUPERVISOR' => 'Broker supervisor', 'BROKER_STAFF' => 'Broker staff', 'BRANCH_MANAGER' => 'Branch manager', 'AGENT' => 'Agent'],
];
