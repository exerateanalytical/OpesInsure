<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * CMP-007 Expired documentation — what has lapsed or lapses within 30 days, for this organisation (kyc.view):
 *   - KYC approvals past or near their expiry (GET kyc/expiring, same rule: APPROVED, not superseded) and EXPIRED files;
 *   - customer documents (identity, registration ...) whose validity ends (documents.valid_until), not superseded
 *     or destroyed.
 */
final class ExpiredDocumentation extends AdminScreenPage
{
    public const HORIZON_DAYS = 30;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-clock';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'compliance/expired-documentation';

    protected static array $permissions = ['kyc.view'];

    protected static string $screen = 'expired_documentation';

    protected static string $group = 'Trust & compliance';

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $limit = now()->addDays(self::HORIZON_DAYS);
        $rows = [];
        $kyc = DB::table('kyc_submissions as k')->leftJoin('parties as p', 'p.id', '=', 'k.party_id')->where('k.tenant_id', $this->tenantId)->whereNull('k.superseded_by_submission_id')
            ->where(fn ($q) => $q->where('k.status', 'EXPIRED')->orWhere(fn ($q) => $q->where('k.status', 'APPROVED')->whereNotNull('k.expires_at')->where('k.expires_at', '<=', $limit)))
            ->orderBy('k.expires_at')->limit(500)->get(['k.id', 'k.subject_kind', 'k.kyc_level', 'k.status', 'k.expires_at', 'k.expired_at', 'p.display_name']);
        foreach ($kyc as $k) {
            $until = $k->expires_at ?? $k->expired_at;
            $rows['kyc:'.$k->id] = ['__key' => 'kyc:'.$k->id, 'id' => 'kyc:'.$k->id, 'party' => $k->display_name ?? '—', 'item' => __('admin_screens.kyc_file', ['level' => Columns::humanise($k->kyc_level)]),
                'kind' => Columns::humanise($k->subject_kind ?? 'INDIVIDUAL'), 'valid_until' => $until, 'state' => $this->state($until, $k->status === 'EXPIRED')];
        }
        $docs = DB::table('documents as d')->leftJoin('parties as p', 'p.id', '=', 'd.party_id')->where('d.tenant_id', $this->tenantId)->whereNotNull('d.party_id')
            ->whereNotNull('d.valid_until')->where('d.valid_until', '<=', $limit)->whereNull('d.superseded_by_document_id')->whereNull('d.destroyed_at')
            ->where(fn ($q) => $q->whereNull('d.status')->orWhereNotIn('d.status', ['REVOKED', 'CANCELLED', 'DESTROYED', 'REPLACED', 'SUPERSEDED']))
            ->orderBy('d.valid_until')->limit(500)->get(['d.id', 'd.title', 'd.document_type_code', 'd.category', 'd.valid_until', 'p.display_name', 'p.type']);
        foreach ($docs as $d) {
            $rows['doc:'.$d->id] = ['__key' => 'doc:'.$d->id, 'id' => 'doc:'.$d->id, 'party' => $d->display_name ?? '—', 'item' => $d->title ?: Columns::humanise($d->document_type_code ?? $d->category),
                'kind' => Columns::humanise($d->type ?? ''), 'valid_until' => $d->valid_until, 'state' => $this->state($d->valid_until, false)];
        }
        uasort($rows, fn ($a, $b) => strcmp((string) $a['valid_until'], (string) $b['valid_until']));

        return $rows;
    }

    private function state(?string $until, bool $expired): string
    {
        return $expired || ($until !== null && $until < now()->toDateTimeString()) ? 'EXPIRED' : 'EXPIRING';
    }

    public function kpis(): array
    {
        $rows = collect($this->rows());

        return [
            self::kpi('expired_items', $rows->where('state', 'EXPIRED')->count(), 'danger'),
            self::kpi('expiring_30d', $rows->where('state', 'EXPIRING')->count(), 'warning'),
            self::kpi('kyc_files', $rows->filter(fn ($r) => str_starts_with($r['id'], 'kyc:'))->count()),
            self::kpi('documents', $rows->filter(fn ($r) => str_starts_with($r['id'], 'doc:'))->count()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int|string $page, int|string $recordsPerPage) => self::pageOf($this->rows(), $search, ['party', 'item'], $page, $recordsPerPage))
            ->columns([
                TextColumn::make('party')->label(self::col('customer'))->searchable(),
                TextColumn::make('kind')->label(self::col('type'))->placeholder('—'),
                TextColumn::make('item')->label(self::col('document'))->wrap(),
                Columns::date('valid_until', false, self::col('valid_until')),
                TextColumn::make('state')->label(self::col('status'))->badge()->formatStateUsing(fn ($state) => __('admin_screens.doc_states.'.$state))
                    ->color(fn ($state): string => $state === 'EXPIRED' ? 'danger' : 'warning'),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
