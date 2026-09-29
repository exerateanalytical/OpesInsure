<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Filament\Shared\Actions\KycActions;
use App\Models\KycSubmission;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base of the broker KYC screens BRK-019 .. BRK-023. Reads mirror routes/kyc.php GET kyc/submissions, kyc/expiring
 * (kyc.view); the screens also open for kyc.manage, the permission of the KYC writes a broker performs on its own
 * clients (open / attach / sources / submit / remediate: POST kyc/parties/{p}/submissions ...). Rows = the portal
 * tenant's submissions of the caller's book only (BookScope). Actions = KycActions (same services + permissions).
 */
abstract class KycScreen extends BrokerScreen
{
    protected static array $permissions = ['kyc.view', 'kyc.manage'];

    protected static string $group = 'kyc';

    /** The caller's book submissions in the portal tenant. */
    public static function submissions(?string $tenantId): Builder
    {
        return self::inBook(KycSubmission::query()->with('party')->where('kyc_submissions.tenant_id', $tenantId ?? '00000000-0000-0000-0000-000000000000'), 'kyc_submissions.party_id');
    }

    protected function submissionTable(Table $table, Builder $query, array $actions): Table
    {
        return $table
            ->query(fn () => $query)
            ->columns([
                self::col('party.display_name', 'customer')->searchable(),
                self::col('subject_kind')->formatStateUsing(fn (?string $state) => self::code('subject_kind', $state)),
                self::col('kyc_level')->formatStateUsing(fn (?string $state) => self::code('kyc_level', $state)),
                self::col('status')->badge()->formatStateUsing(fn (?string $state) => self::code('kyc_status', $state)),
                self::col('screening_status', 'screening')->formatStateUsing(fn (?string $state) => self::code('status', $state)),
                self::col('submitted_at')->since(),
                self::col('expires_at')->date(),
            ])
            ->recordUrl(fn (KycSubmission $r) => KycCasePage::getUrl(['submission' => $r->getKey()]))
            ->recordActions($actions)
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }

    /** @return list<\Filament\Actions\Action> the broker-relevant KYC actions (each hidden unless its status and permission allow). */
    protected static function caseActions(): array
    {
        return [KycActions::attachDocument(), KycActions::declareSources(), KycActions::startReview(), KycActions::requestInformation(),
            KycActions::recommend(), KycActions::decide(), KycActions::remediate()];
    }
}
