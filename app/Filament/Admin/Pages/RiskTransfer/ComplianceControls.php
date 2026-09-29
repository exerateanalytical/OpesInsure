<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Application\Compliance\Catalogue\ComplianceCatalogueService;
use App\Filament\Shared\Actions\ComplianceCatalogueActions;
use Filament\Tables\Table;

/** AML / ICT control catalogue with the tenant's latest assessment — GET compliance-catalogue/controls (compliance.catalogue.view). */
final class ComplianceControls extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-clipboard-check';

    protected static ?int $navigationSort = 48;

    protected static ?string $slug = 'risk-transfer/compliance-controls';

    protected static array $permissions = ['compliance.catalogue.view'];

    protected static string $screen = 'compliance_controls';

    protected static string $group = 'Trust & compliance';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(collect(app(ComplianceCatalogueService::class)->controls(null, $t))->map(fn ($c) => collect($c)->except(['evidence_types'])->all())),
            ['framework' => 'text', 'control_code' => 'text', 'domain' => 'text', 'requirement_summary' => 'text', 'current_status' => 'status'],
            [ComplianceCatalogueActions::indicatorFlag(), ComplianceCatalogueActions::refreshConfigure(), ComplianceCatalogueActions::refreshApprove()],
            [ComplianceCatalogueActions::controlAssess()]);
    }
}
