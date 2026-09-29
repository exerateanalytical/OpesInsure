<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

/**
 * Broker portal screens BRK-002 .. BRK-024 (launch 2026-10-02). BRK-002 Operations Dashboard is the /broker home
 * (PortalDashboard + BrokerOperationsWidget); the others are the pages below, mounted by BrokerPanelProvider.
 */
final class BrokerScreensA
{
    /** Screen id => page. */
    public const SCREENS = [
        'BRK-004' => CustomerDashboardPage::class,
        'BRK-006' => ClaimsDashboardPage::class,
        'BRK-009' => LeadDirectoryPage::class,
        'BRK-010' => LeadDetailsPage::class,
        'BRK-011' => LeadAssignmentPage::class,
        'BRK-016' => CustomerTimelinePage::class,
        'BRK-017' => DuplicateCustomersPage::class,
        'BRK-018' => PortfolioTransferPage::class,
        'BRK-019' => KycDashboardPage::class,
        'BRK-020' => KycQueuePage::class,
        'BRK-021' => KycCasePage::class,
        'BRK-022' => KycRemediationPage::class,
        'BRK-023' => ExpiringDocumentsPage::class,
        'BRK-024' => CorporateDueDiligencePage::class,
    ];

    public const PAGES = [
        CustomerDashboardPage::class, ClaimsDashboardPage::class, LeadDirectoryPage::class, LeadDetailsPage::class, LeadAssignmentPage::class,
        CustomerTimelinePage::class, DuplicateCustomersPage::class, PortfolioTransferPage::class, KycDashboardPage::class, KycQueuePage::class,
        KycCasePage::class, KycRemediationPage::class, ExpiringDocumentsPage::class, CorporateDueDiligencePage::class,
    ];
}
