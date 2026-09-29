<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Models\Claim;
use Filament\Actions\ActionGroup;
use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * Claims of the caller's book (portal tenant + PortalScope::narrowTable('claims') = claims on the book's policies),
 * opening the claim page. Row actions are the shared claim workflow actions (ClaimActions / ClaimCaseActions), each
 * gated by its API permission and PortalScope::isOwnRecord.
 */
abstract class ClaimScreen extends BrokerScreen
{
    protected static ?string $group = 'Claims operations';

    protected static array $readPermissions = ['claims.view'];

    public const CLOSED = ['CLOSED', 'PAID', 'DECLINED'];

    /** @param list<string> $statuses */
    protected function claims(array $statuses = []): Builder
    {
        $q = $this->book(Claim::class, 'claims')->with(['policy.party', 'assignee']);

        return $statuses === [] ? $q : $q->whereIn('claims.status', $statuses);
    }

    protected function columns(): array
    {
        return [
            self::text('claim_number', 'claim')->searchable()->copyable(),
            self::text('policy.policy_number', 'policy'),
            self::text('policy.party.display_name', 'customer'),
            self::status(),
            self::money('estimated_loss_minor', 'estimated_loss'),
            self::text('assignee.full_name', 'assignee'),
            self::date('loss_occurred_at', 'loss_occurred_at', false),
            self::date('updated_at', 'updated_at'),
        ];
    }

    protected function defaultSort(): string
    {
        return 'updated_at';
    }

    /** @param list<\Filament\Actions\Action> $actions */
    protected static function group(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)->label(__('broker_screens_b.actions'))->icon('lucide-zap')->button();
    }

    protected function recordLink(Model $record): ?string
    {
        return self::viewUrl(ClaimResource::class, $record);
    }
}
