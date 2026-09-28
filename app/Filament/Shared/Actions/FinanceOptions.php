<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Domain\Tenancy\TenantContext;
use App\Models\Carrier;
use App\Models\Partner;

/** Select options shared by the finance actions (commissions, statements, settlements, bordereaux). */
final class FinanceOptions
{
    /** @return array<string, string> carriers (a national register, not tenant-owned) */
    public static function carriers(): array
    {
        return Carrier::query()->where('status', 'ACTIVE')->with('party')->limit(500)->get()
            ->mapWithKeys(fn (Carrier $c) => [$c->id => $c->trade_name ?: ($c->legal_name ?: ($c->party?->display_name ?? (string) $c->cima_code))])->sort()->all();
    }

    /** @return array<string, string> partners of the current tenant */
    public static function partners(): array
    {
        return Partner::query()->where('tenant_id', app(TenantContext::class)->id())->with('party')->limit(500)->get()
            ->mapWithKeys(fn (Partner $p) => [$p->id => $p->trade_name ?: ($p->legal_name ?: ($p->party?->display_name ?? (string) $p->licence_number ?: $p->id))])->sort()->all();
    }

    /** @return array<string, string> */
    public static function currencies(): array
    {
        return ['XAF' => 'XAF (FCFA)', 'EUR' => 'EUR', 'USD' => 'USD'];
    }
}
