<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Filament\Shared\Actions\ApprovalActions;
use App\Filament\Shared\Columns;
use App\Models\ApprovalRequest;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * CMP-012..015 dedicated exception review queues: the approval inbox (GET approvals, approvals.inbox.view) narrowed
 * to one family of action codes. Decisions are the shared ApprovalActions (approvals.decide → ApprovalService:
 * maker-checker, SoD, matrix levels), exactly as in the inbox.
 */
abstract class ExceptionReviewQueue extends AdminScreenPage
{
    /** @var list<string> */
    protected const ACTION_CODES = [];

    protected static array $permissions = ['approvals.inbox.view'];

    protected static string $group = 'Approvals';

    protected function base(): Builder
    {
        return ApprovalRequest::query()->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')->whereIn('action_code', static::ACTION_CODES);
    }

    public function kpis(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $pending = $this->base()->where('status', 'PENDING');
        $old = (clone $pending)->where('created_at', '<', now()->subDays(2))->count();

        return [
            self::kpi('pending', (clone $pending)->count(), (clone $pending)->exists() ? 'warning' : 'success'),
            self::kpi('older_48h', $old, $old > 0 ? 'danger' : 'success'),
            self::kpi('approved_30d', $this->base()->whereIn('status', ['APPROVED', 'AUTO_APPROVED'])->where('decided_at', '>=', now()->subDays(30))->count(), 'success'),
            self::kpi('rejected_30d', $this->base()->where('status', 'REJECTED')->where('decided_at', '>=', now()->subDays(30))->count()),
        ];
    }

    /** @return list<string> */
    public static function actionCodes(): array
    {
        return static::ACTION_CODES;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->base())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('action_code')->label(self::col('exception_type'))->badge()->formatStateUsing(fn ($state) => __('admin_screens.action_codes.'.str_replace('.', '_', (string) $state))),
                TextColumn::make('subject_type')->label(self::col('subject'))->formatStateUsing(fn ($state) => Columns::humanise($state)),
                TextColumn::make('amount')->label(self::col('amount'))->alignEnd()
                    ->formatStateUsing(fn ($state, ApprovalRequest $record) => $state === null ? '—' : number_format((float) $state, 0, ',', ' ').' '.($record->currency ?: 'XAF')),
                TextColumn::make('reason')->label(self::col('reason'))->wrap()->limit(80),
                TextColumn::make('requester.full_name')->label(self::col('requested_by')),
                TextColumn::make('approvals_count')->label(self::col('level'))->formatStateUsing(fn ($state, ApprovalRequest $record) => $state.' / '.$record->required_approvals),
                Columns::status('status', self::col('status')),
                Columns::date('created_at', true, self::col('created_at')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(self::col('status'))
                    ->options(collect(['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', 'AUTO_APPROVED'])->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all())->default('PENDING'),
            ])
            ->recordActions([
                Action::make('details')->label(__('web_experience.approval.heading'))->icon('lucide-eye')->color('gray')
                    ->modalHeading(__('web_experience.approval.heading'))->modalSubmitAction(false)
                    ->modalContent(fn ($record) => view('filament.shared.approval-panel', ['approval' => app(\App\Application\WebExperiences\ApprovalPanelData::class)->for($record, auth()->user())])),
                ...ApprovalActions::all(),
            ])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
