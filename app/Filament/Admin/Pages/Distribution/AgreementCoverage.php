<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Distribution;

use App\Application\CarrierOperations\Agreements\BulkAgreementImporter;
use BackedEnum;
use Filament\Pages\Page;

/** S10 — brokers/agents × insurers: ACTIVE agreement or not, and who currently has zero sellable products (distribution.agreements.view). */
final class AgreementCoverage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-grid-3x3';

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'agreement-coverage';

    protected string $view = 'filament.admin.pages.distribution.agreement-coverage';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('distribution.agreements.view');
    }

    public static function getNavigationGroup(): ?string { return __('web_experience.sections.group_distribution'); }

    public static function getNavigationLabel(): string { return __('bulk_agreements.coverage.title'); }

    public function getTitle(): string { return __('bulk_agreements.coverage.title'); }

    protected function getViewData(): array
    {
        $m = app(BulkAgreementImporter::class)->coverage(BulkAgreements::scope());

        return $m + ['zero' => array_values(array_filter($m['rows'], fn ($r) => $r['sellable'] === 0))];
    }
}
