<?php

declare(strict_types=1);

namespace App\Application\MarketData\Import;

use App\Models\Carrier;
use App\Models\Partner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Resolves import rows onto the EXISTING official-register insurers and brokers. Imports never create either. */
trait ResolvesMarketParties
{
    private function carrier(array $row, string $field = 'insurer'): Carrier
    {
        $ref = trim((string) ($row[$field] ?? ''));
        if ($ref === '') {
            throw ValidationException::withMessages([$field => ["{$field} is required"]]);
        }
        $carrier = Carrier::where('canonical_id', $ref)->orWhere('insurer_code', strtoupper($ref))->first()
            ?? Carrier::whereRaw('upper(legal_name) = ?', [Str::upper($ref)])->first();

        return $carrier ?? throw ValidationException::withMessages([$field => ["Unknown insurer {$ref} (imports never create insurers)"]]);
    }

    private function broker(array $row): Partner
    {
        $q = Partner::where('type', 'BROKER');
        $seq = trim((string) ($row['official_sequence'] ?? ''));
        $name = trim((string) ($row['legal_name'] ?? $row['broker'] ?? ''));
        $partner = null;
        if ($seq !== '' && ctype_digit($seq)) {
            $partner = (clone $q)->where('is_official_register', true)->where('regulator_sequence', (int) $seq)->first();
        }
        if (! $partner && $name !== '') {
            $partner = (clone $q)->where(fn ($w) => $w->where('canonical_id', $name)
                ->orWhereRaw('upper(legal_name) = ?', [Str::upper($name)])->orWhereRaw('upper(trade_name) = ?', [Str::upper($name)]))->first();
        }

        return $partner ?? throw ValidationException::withMessages(['broker' => ['Broker not in the DGTCFM register ('.($name ?: $seq).'); DGTCFM is the authorization source of truth, enrichment never creates brokers']]);
    }

    /** @return list<string> */
    private function list(mixed $v): array
    {
        if (is_array($v)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $v)), 'strlen'));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[;|]/', (string) $v) ?: []), 'strlen'));
    }

    private function error(\Throwable $e): string
    {
        return $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
    }
}
