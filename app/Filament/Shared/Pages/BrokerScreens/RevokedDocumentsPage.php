<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Policies\PolicyResource;
use App\Models\{Policy, PolicyCertificate};
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\DB;

/**
 * BRK-072 Revoked / Replaced Documents (WF-031): certificates of the book's policies that were voided (VOID, POST
 * certificates/{c}/void), with the reason, who voided it and when, and whether a replacement certificate is VALID on the
 * same policy. Read-only register (voiding is the insurer's act); a row opens the policy.
 */
final class RevokedDocumentsPage extends BrokerScreen
{
    protected static string $key = 'revoked_documents';

    protected static ?string $slug = 'revoked-documents';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-minus';

    protected static ?int $navigationSort = 81;

    protected static ?string $group = 'Policy operations';

    protected static array $readPermissions = ['policies.read'];

    protected function query(): Builder
    {
        return PolicyCertificate::query()->with('policy.party')->where('policy_certificates.status', '!=', 'VALID')
            ->whereIn('policy_certificates.policy_id', $this->visibleIds('policies'));
    }

    protected function defaultSort(): string
    {
        return 'updated_at';
    }

    protected function columns(): array
    {
        return [
            self::text('serial_number', 'serial')->searchable()->copyable(),
            self::text('policy.policy_number', 'policy'),
            self::text('policy.party.display_name', 'customer'),
            self::status(),
            self::date('issued_at', 'issued_at'),
            self::date('voided_at', 'voided_at'),
            self::text('void_reason', 'reason')->wrap(),
            TextColumn::make('voided_by_name')->label(self::col('voided_by'))
                ->state(fn (PolicyCertificate $record) => $record->voided_by ? DB::table('users')->where('id', $record->voided_by)->value('full_name') : null)->placeholder('—'),
            TextColumn::make('replacement')->label(self::col('replacement'))->state(fn (PolicyCertificate $record) => DB::table('policy_certificates')
                ->where(['policy_id' => $record->policy_id, 'status' => 'VALID'])->where('id', '!=', $record->id)->value('serial_number'))->placeholder('—'),
        ];
    }

    protected function recordLink(Model $record): ?string
    {
        return $record instanceof PolicyCertificate && $record->policy instanceof Policy ? self::viewUrl(PolicyResource::class, $record->policy) : null;
    }
}
