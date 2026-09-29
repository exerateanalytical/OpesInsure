<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Kyc\KycRequirementService;
use App\Filament\Shared\Columns;
use App\Models\KycSubmission;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * CMP-006 Corporate due diligence — the KYC files of corporate subjects (kyc_submissions.subject_kind = CORPORATE;
 * GET kyc/submissions, kyc.view, tenant-scoped) with their level, screening, recommendation, expiry and the mandatory
 * requirements still missing (KycRequirementService::evaluate, the same check the review decision uses). Review and
 * decision stay on KYC reviews.
 */
final class CorporateDueDiligence extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-building-2';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'compliance/corporate-due-diligence';

    protected static array $permissions = ['kyc.view'];

    protected static string $screen = 'corporate_due_diligence';

    protected static string $group = 'Trust & compliance';

    private function base(): \Illuminate\Database\Eloquent\Builder
    {
        return KycSubmission::query()->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')->where('subject_kind', 'CORPORATE')->whereNull('superseded_by_submission_id');
    }

    public function kpis(): array
    {
        return [
            self::kpi('corporate_files', $this->base()->count()),
            self::kpi('awaiting_review', $this->base()->whereIn('status', ['SUBMITTED', 'REVIEWING', 'PENDING_APPROVAL', 'MORE_INFO_REQUIRED'])->count(), 'warning'),
            self::kpi('enhanced', $this->base()->where('kyc_level', 'ENHANCED')->count()),
            self::kpi('expiring_30d', $this->base()->where('status', 'APPROVED')->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(30))->count(), 'danger'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->base()->with('party'))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('party.display_name')->label(self::col('company'))->searchable()->placeholder('—'),
                TextColumn::make('kyc_level')->label(self::col('level'))->badge()->formatStateUsing(fn ($state) => Columns::humanise($state)),
                Columns::status('status', self::col('status')),
                Columns::status('screening_status', self::col('screening')),
                TextColumn::make('recommended_outcome')->label(self::col('recommendation'))->placeholder('—')->formatStateUsing(fn ($state) => Columns::humanise($state)),
                TextColumn::make('missing')->label(self::col('missing_requirements'))->wrap()
                    ->state(fn (KycSubmission $r) => count(rescue(fn () => app(KycRequirementService::class)->evaluate($r)['missing'], [], false)))
                    ->badge()->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'success'),
                Columns::date('submitted_at', true, self::col('submitted')),
                Columns::date('expires_at', false, self::col('expires')),
            ])
            ->filters([
                SelectFilter::make('status')->label(self::col('status'))
                    ->options(collect(['DRAFT', 'SUBMITTED', 'REVIEWING', 'MORE_INFO_REQUIRED', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'EXPIRED'])->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all()),
            ])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
