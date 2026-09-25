<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Models\Carrier;
use App\Models\Partner;
use App\Models\Regulatory\OrganizationNameHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-SEED-003 — effective-dated name/brand history for insurers (carriers) and intermediaries (partners).
 * A change of legal/trade/short name appends a row and closes the previous one; nothing is overwritten
 * (a DB trigger rejects rewriting a recorded name or deleting a row). Wired as a model observer by
 * CimaReconciliationServiceProvider, so the official-register seeder and admin edits both feed it.
 */
final class OrganizationNameHistoryService
{
    private const TYPES = [Carrier::class => 'CARRIER', Partner::class => 'PARTNER'];

    public function capture(Model $org, ?string $source = null): ?OrganizationNameHistory
    {
        $type = self::TYPES[$org::class] ?? null;
        if ($type === null) {
            return null;
        }
        $names = ['legal_name' => $org->legal_name, 'trade_name' => $org->trade_name, 'short_name' => $type === 'CARRIER' ? $org->short_name : null];
        if ($names['legal_name'] === null && $names['trade_name'] === null) {
            return null;
        }
        $current = $this->current($type, $org->getKey());
        if ($current && $current->legal_name === $names['legal_name'] && $current->trade_name === $names['trade_name'] && $current->short_name === $names['short_name']) {
            return $current;
        }
        $year = $org->reference_year;
        $from = $year && (! $current || $year > (int) $current->reference_year) ? "{$year}-01-01" : now()->toDateString();
        if ($current && $current->effective_from->toDateString() > $from) {
            $from = $current->effective_from->toDateString();
        }

        return DB::transaction(function () use ($current, $type, $org, $names, $from, $year, $source) {
            $current?->update(['effective_until' => $from]);

            return OrganizationNameHistory::create($names + [
                'subject_type' => $type, 'subject_id' => $org->getKey(), 'effective_from' => $from, 'reference_year' => $year,
                'source' => $source ?? ($org->is_official_register ? 'OFFICIAL_REGISTER' : 'ADMIN'),
                'source_authority' => $org->source_authority, 'recorded_by' => auth()->id(),
            ]);
        });
    }

    public function current(string $type, string $id): ?OrganizationNameHistory
    {
        return OrganizationNameHistory::where('subject_type', $type)->where('subject_id', $id)->whereNull('effective_until')
            ->orderByDesc('effective_from')->orderByDesc('created_at')->first();
    }

    /** @return Collection<int, OrganizationNameHistory> */
    public function history(Model $org): Collection
    {
        return OrganizationNameHistory::where('subject_type', self::TYPES[$org::class] ?? '')->where('subject_id', $org->getKey())
            ->orderBy('effective_from')->orderBy('created_at')->get();
    }

    /** Name in force on a date (e.g. to print a document dated in a prior year). */
    public function nameOn(Model $org, string $date): ?string
    {
        $row = OrganizationNameHistory::where('subject_type', self::TYPES[$org::class] ?? '')->where('subject_id', $org->getKey())
            ->whereDate('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>', $date))
            ->orderByDesc('effective_from')->first();

        return $row?->trade_name ?? $row?->legal_name;
    }
}
