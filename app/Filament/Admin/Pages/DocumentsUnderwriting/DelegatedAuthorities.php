<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentsUnderwriting;

use App\Filament\Shared\Actions\DelegatedAuthorityActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Carrier delegated-authority agreements (the partners of the current tenant). Opened by carrier.authority.manage or
 * carrier.authority.approve; create / approve / check through DelegatedAuthorityActions.
 */
final class DelegatedAuthorities extends DocUwPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-signature';

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'delegated-authorities';

    protected static ?array $permissions = ['carrier.authority.manage', 'carrier.authority.approve'];

    protected static string $screen = 'delegated_authorities';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters): array {
                if ($this->tenantId === null) {
                    return [];
                }

                return self::keyed(DB::table('delegated_authority_agreements as a')
                    ->join('partners', 'partners.id', '=', 'a.partner_id')->join('parties as pp', 'pp.id', '=', 'partners.party_id')
                    ->join('carriers', 'carriers.id', '=', 'a.carrier_id')->join('parties as cp', 'cp.id', '=', 'carriers.party_id')
                    ->where('partners.tenant_id', $this->tenantId)
                    ->when($filters['status']['value'] ?? null, fn ($q, $v) => $q->where('a.status', $v))
                    ->orderByDesc('a.created_at')->limit(200)
                    ->get(['a.id', 'a.agreement_number', 'a.status', 'a.effective_from', 'a.effective_until', 'a.permitted_lines', 'a.territories',
                        'a.max_policy_premium_minor', 'a.max_claim_authority_minor', 'pp.display_name as partner', 'cp.display_name as carrier']));
            })
            ->columns([
                TextColumn::make('agreement_number')->label(self::col('agreement_number')),
                TextColumn::make('carrier')->label(self::col('carrier')),
                TextColumn::make('partner')->label(self::col('partner')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('effective_from')->label(self::col('effective_from'))->date(),
                TextColumn::make('effective_until')->label(self::col('effective_until'))->date(),
                TextColumn::make('permitted_lines')->label(self::col('permitted_lines')),
                \App\Filament\Shared\Columns::money('max_policy_premium_minor', 'currency', self::col('max_policy_premium')),
            ])
            ->filters([SelectFilter::make('status')->label(self::col('status'))->options(['DRAFT' => 'DRAFT', 'ACTIVE' => 'ACTIVE'])])
            ->headerActions([DelegatedAuthorityActions::create()])
            ->recordActions([DelegatedAuthorityActions::approve(), DelegatedAuthorityActions::check()])
            ->emptyStateHeading(__('doc_uw_actions.empty'));
    }
}
