<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentsUnderwriting;

use App\Application\Cases\Models\WorkCase;
use App\Filament\Shared\Actions\CorrespondenceActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * REQ-COR-001 correspondence register (GET correspondence, cases.view): same tenant + confidentiality filter as
 * CorrespondenceController::index (rows on a case the caller cannot see are hidden). Register / dispatch / outcome
 * through CorrespondenceActions (cases.manage).
 */
final class CorrespondenceRegister extends DocUwPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-mail';

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'correspondence';

    protected static ?array $permissions = ['cases.view'];

    protected static string $screen = 'correspondence';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $tenant = $this->tenantId;

                return self::keyed(DB::table('correspondence_register')->where('tenant_id', $tenant)
                    ->where(fn ($q) => $q->whereNull('case_id')->orWhereIn('case_id', WorkCase::query()->where('tenant_id', $tenant)->select('id')))
                    ->when($filters['status']['value'] ?? null, fn ($q, $v) => $q->where('status', $v))
                    ->when($filters['direction']['value'] ?? null, fn ($q, $v) => $q->where('direction', $v))
                    ->orderByDesc('created_at')->limit(200)->get());
            })
            ->columns([
                TextColumn::make('reference_number')->label(self::col('reference')),
                TextColumn::make('direction')->label(self::col('direction'))->badge(),
                TextColumn::make('channel')->label(self::col('channel')),
                TextColumn::make('counterparty_name')->label(self::col('counterparty')),
                TextColumn::make('subject_line')->label(self::col('subject'))->limit(60),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('proof_type')->label(self::col('proof')),
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->label(self::col('status'))->options(array_combine($s = ['RECEIVED', 'DRAFT', 'DISPATCHED', 'DELIVERED', 'FAILED'], $s)),
                SelectFilter::make('direction')->label(self::col('direction'))->options(['INBOUND' => 'INBOUND', 'OUTBOUND' => 'OUTBOUND']),
            ])
            ->headerActions([CorrespondenceActions::register()])
            ->recordActions([CorrespondenceActions::dispatch(), CorrespondenceActions::outcome()])
            ->emptyStateHeading(__('doc_uw_actions.empty'));
    }
}
