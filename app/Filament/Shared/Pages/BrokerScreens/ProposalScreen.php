<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Proposals\ProposalResource;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\{Builder, Model};

/** Proposals of the caller's book (portal tenant + PortalScope::narrowTable('proposals')), opening the proposal page. */
abstract class ProposalScreen extends BrokerScreen
{
    protected static array $readPermissions = ['quotes.read'];

    /** @param list<string> $statuses */
    protected function proposals(array $statuses = []): Builder
    {
        $q = $this->book(Proposal::class, 'proposals')->with('party');

        return $statuses === [] ? $q : $q->whereIn('proposals.status', $statuses);
    }

    protected function columns(): array
    {
        return [
            self::text('proposal_number', 'proposal')->searchable()->copyable(),
            self::text('party.display_name', 'customer')->searchable(),
            self::status(),
            self::date('submitted_at', 'submitted_at'),
            self::date('updated_at', 'updated_at'),
        ];
    }

    protected function defaultSort(): string
    {
        return 'updated_at';
    }

    protected function recordLink(Model $record): ?string
    {
        return self::viewUrl(ProposalResource::class, $record);
    }
}
