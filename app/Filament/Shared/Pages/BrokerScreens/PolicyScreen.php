<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Policies\PolicyResource;
use App\Models\Policy;
use Illuminate\Database\Eloquent\{Builder, Model};

/** Policies of the caller's book (portal tenant + PortalScope::narrowTable('policies')), opening the policy page. */
abstract class PolicyScreen extends BrokerScreen
{
    protected static ?string $group = 'Policy operations';

    protected static array $readPermissions = ['policies.read'];

    /** @param list<string> $statuses */
    protected function policies(array $statuses = []): Builder
    {
        $q = $this->book(Policy::class, 'policies')->with('party');

        return $statuses === [] ? $q : $q->whereIn('policies.status', $statuses);
    }

    protected function columns(): array
    {
        return [
            self::text('policy_number', 'policy')->searchable()->copyable(),
            self::text('party.display_name', 'customer')->searchable(),
            self::status(),
            self::money('premium_minor', 'premium'),
            self::date('coverage_starts_at', 'coverage_starts_at', false),
            self::date('coverage_ends_at', 'coverage_ends_at', false),
        ];
    }

    protected function defaultSort(): string
    {
        return 'updated_at';
    }

    protected function recordLink(Model $record): ?string
    {
        return $record instanceof Policy ? self::viewUrl(PolicyResource::class, $record) : null;
    }
}
