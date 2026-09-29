<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Trust\FraudReviewService;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Filament\Shared\Columns;
use App\Models\RiskAlert;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * CMP-011 Claim fraud review — open risk alerts raised on claims, with the claim's own context (number, status,
 * estimated loss, fraud flag) and the signals. Decision: POST risk-alerts/{alert}/decision (fraud.alert.decide) through
 * FraudReviewService::decide (assignee cannot decide; tenant-scoped).
 */
final class ClaimFraudReview extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-question';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'compliance/claim-fraud-review';

    protected static array $permissions = ['fraud.alert.decide', 'trust.fraud-alerts.decide'];

    protected static string $screen = 'claim_fraud_review';

    protected static string $group = 'Trust & compliance';

    public const DECISIONS = ['CLEARED', 'CONFIRMED', 'MONITOR', 'ESCALATED'];

    public function kpis(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $q = fn () => RiskAlert::query()->where('tenant_id', $this->tenantId)->whereIn('subject_type', ['claim', 'CLAIM', \App\Models\Claim::class]);

        return [
            self::kpi('open_claim_alerts', $q()->whereIn('status', RiskDashboard::OPEN)->count(), 'warning'),
            self::kpi('flagged_claims', DB::table('claims')->where('tenant_id', $this->tenantId)->where('fraud_flag', true)->count()),
            self::kpi('confirmed_30d', $q()->whereIn('decision', ['CONFIRMED', 'CONFIRMED_FRAUD'])->where('decided_at', '>=', now()->subDays(30))->count(), 'danger'),
            self::kpi('cleared_30d', $q()->where('decision', 'CLEARED')->where('decided_at', '>=', now()->subDays(30))->count(), 'success'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => RiskAlert::query()->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')
                ->whereIn('subject_type', ['claim', 'CLAIM', \App\Models\Claim::class])->whereIn('status', RiskDashboard::OPEN))
            ->defaultSort('risk_score', 'desc')
            ->columns([
                TextColumn::make('claim_number')->label(self::col('claim'))
                    ->state(fn (RiskAlert $r) => DB::table('claims')->where('tenant_id', $r->tenant_id)->where('id', $r->subject_id)->value('claim_number') ?? '—'),
                TextColumn::make('claim_status')->label(self::col('claim_status'))->badge()
                    ->state(fn (RiskAlert $r) => Columns::humanise(DB::table('claims')->where('tenant_id', $r->tenant_id)->where('id', $r->subject_id)->value('status') ?? '')),
                TextColumn::make('alert_type')->label(self::col('alert_type'))->formatStateUsing(fn ($state) => Columns::humanise($state)),
                TextColumn::make('severity')->label(self::col('severity'))->badge()->color(fn ($state): string => in_array($state, ['HIGH', 'CRITICAL'], true) ? 'danger' : 'warning'),
                TextColumn::make('risk_score')->label(self::col('risk_score'))->numeric()->sortable(),
                TextColumn::make('signals')->label(self::col('signals'))->wrap()
                    ->state(fn (RiskAlert $r) => collect((array) $r->signals)->map(fn ($v, $k) => is_int($k) ? (is_scalar($v) ? (string) $v : json_encode($v)) : $k.': '.(is_scalar($v) ? (string) $v : json_encode($v)))->take(6)->implode(' · ') ?: '—'),
                Columns::date('review_due_at', true, self::col('review_due')),
            ])
            ->recordActions([
                WorkflowAction::make('fraudDecide', 'fraud.alert.decide', 'admin_screens')->icon('lucide-gavel')
                    ->schema([
                        Select::make('decision')->label(self::col('decision'))->options(collect(self::DECISIONS)->mapWithKeys(fn ($d) => [$d => __('admin_screens.decisions.'.$d)])->all())->required(),
                        Textarea::make('notes')->label(self::col('notes'))->required()->maxLength(4000),
                    ])
                    ->action(fn (Action $action, RiskAlert $record, array $data) => WorkflowAction::run($action, 'fraud.alert.decide',
                        fn () => app(FraudReviewService::class)->decide(RiskAlert::query()->where('tenant_id', $this->tenantId)->findOrFail($record->id), $data['decision'], $data['notes'], auth()->user()), __('admin_screens.fraudDecide.done'))),
            ])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
