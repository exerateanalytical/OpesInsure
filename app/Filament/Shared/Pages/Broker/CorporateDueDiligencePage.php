<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Filament\Shared\Actions\PartyActions;
use App\Filament\Shared\Pages\BrokerCustomersPage;
use App\Models\TenantCustomer;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * BRK-024 Corporate Due Diligence, built on the existing KYC and party golden-record services: the caller's corporate
 * customers (party type ORGANIZATION, BookScope) with their current KYC state and beneficial owners
 * (ownership_interests, GET parties/{p}/ownership). Actions (same services + permissions as the API):
 *   declare / end an owner  PartyActions::addOwnership / endOwnership  parties.relationships.manage
 *   open / submit KYC       PartyActions::kycOpen / kycSubmit          kyc.manage
 * Opens for the KYC read / write permissions and the ownership read / write permissions.
 */
final class CorporateDueDiligencePage extends BrokerScreen
{
    protected static ?string $slug = 'kyc/corporate-due-diligence';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-building-2';

    protected static ?int $navigationSort = 24;

    protected static array $permissions = ['kyc.view', 'kyc.manage', 'parties.manage', 'parties.relationships.manage'];

    protected static string $screen = 'corporate_dd';

    protected static string $group = 'kyc';

    public function table(Table $table): Table
    {
        $t = fn () => $this->tenant();
        $kyc = fn (TenantCustomer $r) => DB::table('kyc_submissions')->where('tenant_id', $t())->where('party_id', $r->party_id)->whereNull('superseded_by_submission_id')->latest('created_at')->first();

        return $table
            ->query(fn () => BrokerCustomersPage::customers($this->tenantId)->whereHas('party', fn ($q) => $q->where('type', 'ORGANIZATION'))->latest('created_at'))
            ->columns([
                self::col('customer_number')->searchable(),
                self::col('party.display_name', 'company')->searchable(),
                TextColumn::make('kyc_status')->label(__('broker_screens_a.columns.kyc_status'))->badge()->placeholder('—')->state(fn (TenantCustomer $r) => self::code('kyc_status', $kyc($r)?->status)),
                TextColumn::make('kyc_level')->label(__('broker_screens_a.columns.kyc_level'))->placeholder('—')->state(fn (TenantCustomer $r) => self::code('kyc_level', $kyc($r)?->kyc_level)),
                TextColumn::make('owners')->label(__('broker_screens_a.columns.owners'))->placeholder('—')->wrap()
                    ->state(fn (TenantCustomer $r) => DB::table('ownership_interests')->join('parties', 'parties.id', '=', 'ownership_interests.owner_party_id')
                        ->where('ownership_interests.owned_party_id', $r->party_id)->where('ownership_interests.status', 'ACTIVE')->get(['parties.display_name', 'ownership_interests.percentage'])
                        ->map(fn ($o) => $o->display_name.' ('.(float) $o->percentage.' %)')->implode(', ') ?: null),
                TextColumn::make('expires')->label(__('broker_screens_a.columns.expires_at'))->date()->placeholder('—')->state(fn (TenantCustomer $r) => $kyc($r)?->expires_at),
            ])
            ->recordActions([PartyActions::addOwnership(), PartyActions::endOwnership(), PartyActions::kycOpen(), PartyActions::kycSubmit()])
            ->emptyStateHeading(__('broker_screens_a.corporate_dd.empty'));
    }
}
