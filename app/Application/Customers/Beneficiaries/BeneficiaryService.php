<?php

declare(strict_types=1);

namespace App\Application\Customers\Beneficiaries;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CRM-004 / WF-078 — beneficiary designations as first-class records.
 *
 * A policy's beneficiaries are one versioned SET: replace() supersedes the
 * whole ACTIVE set and writes set_version + 1, so the full history is kept
 * and nothing is updated in place. Rules:
 *  - at least one PRIMARY; PRIMARY allocations total exactly 100 %;
 *    CONTINGENT (if any) total exactly 100 %;
 *  - a beneficiary is either a known party (party_id) or a named person;
 *  - no person twice in the same designation;
 *  - an irrevocable beneficiary cannot be removed or reduced without the
 *    beneficiary's consent evidence (irrevocable_consent_reference).
 * Policyholder ≠ insured ≠ beneficiary (REQ-PTY-002): nothing here assumes the
 * policy's party is a beneficiary.
 */
final class BeneficiaryService
{
    public const DESIGNATIONS = ['PRIMARY', 'CONTINGENT'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function policy(string $policyId, string $tenantId): Policy
    {
        $p = Str::isUuid($policyId) ? Policy::where('tenant_id', $tenantId)->find($policyId) : null;
        abort_unless($p, 404);

        return $p;
    }

    /** @return Collection<int, object> */
    public function current(Policy $policy): Collection
    {
        return DB::table('beneficiary_designations')->where('policy_id', $policy->id)->where('status', 'ACTIVE')
            ->orderByRaw("CASE designation WHEN 'PRIMARY' THEN 0 ELSE 1 END")->orderByDesc('allocation_pct')->get();
    }

    /** @return Collection<int, array{set_version: int, effective_from: string, effective_to: ?string, reason: ?string, designations: list<object>}> */
    public function history(Policy $policy): Collection
    {
        return DB::table('beneficiary_designations')->where('policy_id', $policy->id)->orderByDesc('set_version')->get()
            ->groupBy('set_version')->map(fn ($rows, $v) => [
                'set_version' => (int) $v, 'status' => $rows->first()->status, 'effective_from' => $rows->first()->effective_from,
                'effective_to' => $rows->first()->effective_to, 'reason' => $rows->first()->reason, 'designations' => $rows->values()->all(),
            ])->values();
    }

    /**
     * @param  list<array{designation: string, party_id?: ?string, full_name?: ?string, relationship?: ?string, date_of_birth?: ?string, allocation_pct: numeric, revocable?: bool}>  $items
     * @return Collection<int, object>
     */
    public function replace(Policy $policy, array $items, string $reason, ?string $consentReference, User $actor): Collection
    {
        $rows = $this->validate($items);

        return DB::transaction(function () use ($policy, $rows, $reason, $consentReference, $actor) {
            $previous = DB::table('beneficiary_designations')->where('policy_id', $policy->id)->where('status', 'ACTIVE')->lockForUpdate()->get();
            $this->guardIrrevocable($previous, $rows, $consentReference);
            $now = now();
            $version = (int) DB::table('beneficiary_designations')->where('policy_id', $policy->id)->max('set_version') + 1;
            DB::table('beneficiary_designations')->where('policy_id', $policy->id)->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED', 'effective_to' => $now, 'updated_at' => $now]);
            foreach ($rows as $r) {
                DB::table('beneficiary_designations')->insert($r + [
                    'id' => (string) Str::uuid(), 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'set_version' => $version,
                    'status' => 'ACTIVE', 'effective_from' => $now, 'reason' => $reason, 'designated_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->audit->record('beneficiaries.designated', 'policy', $policy->id, [
                'set_version' => $version, 'previous_set_version' => $version - 1 ?: null, 'count' => count($rows), 'irrevocable_consent_reference' => $consentReference,
            ], $reason);
            $this->outbox->record('policy.beneficiaries.changed', 'policy', $policy->id, ['policy_id' => $policy->id, 'set_version' => $version]);

            return $this->current($policy);
        });
    }

    /** @return list<array<string, mixed>> */
    private function validate(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['beneficiaries' => ['Name at least one primary beneficiary.']]);
        }
        $rows = [];
        $totals = ['PRIMARY' => 0, 'CONTINGENT' => 0];
        $seen = [];
        foreach (array_values($items) as $i => $b) {
            $designation = strtoupper((string) ($b['designation'] ?? ''));
            if (! in_array($designation, self::DESIGNATIONS, true)) {
                throw ValidationException::withMessages(["beneficiaries.{$i}.designation" => ['Choose primary or contingent.']]);
            }
            $cents = (int) round(((float) ($b['allocation_pct'] ?? 0)) * 100);
            if ($cents <= 0 || $cents > 10000) {
                throw ValidationException::withMessages(["beneficiaries.{$i}.allocation_pct" => ['Each share must be more than 0 % and at most 100 %.']]);
            }
            $partyId = $b['party_id'] ?? null;
            $name = trim((string) ($b['full_name'] ?? ''));
            if ($partyId) {
                $party = Str::isUuid($partyId) ? DB::table('parties')->where('id', $partyId)->first(['id', 'display_name']) : null;
                if (! $party) {
                    throw ValidationException::withMessages(["beneficiaries.{$i}.party_id" => ['Unknown person.']]);
                }
                $name = $name !== '' ? $name : $party->display_name;
            } elseif ($name === '') {
                throw ValidationException::withMessages(["beneficiaries.{$i}.full_name" => ['Give the beneficiary\'s name or pick a known person.']]);
            }
            $key = $designation.'|'.($partyId ?: mb_strtolower($name).'|'.($b['date_of_birth'] ?? ''));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["beneficiaries.{$i}" => ['The same person is listed twice.']]);
            }
            $seen[$key] = true;
            $totals[$designation] += $cents;
            $rows[] = [
                'designation' => $designation, 'party_id' => $partyId, 'full_name' => mb_substr($name, 0, 160),
                'relationship' => isset($b['relationship']) ? strtoupper((string) $b['relationship']) : null,
                'date_of_birth' => $b['date_of_birth'] ?? null, 'allocation_pct' => number_format($cents / 100, 2, '.', ''),
                'revocable' => (bool) ($b['revocable'] ?? true),
            ];
        }
        if ($totals['PRIMARY'] === 0) {
            throw ValidationException::withMessages(['beneficiaries' => ['Name at least one primary beneficiary.']]);
        }
        foreach ($totals as $designation => $sum) {
            if ($sum !== 0 && $sum !== 10000) {
                throw ValidationException::withMessages(['beneficiaries' => [ucfirst(strtolower($designation)).' shares must add up to exactly 100 % (now '.number_format($sum / 100, 2).' %).']]);
            }
        }

        return $rows;
    }

    private function guardIrrevocable(Collection $previous, array $rows, ?string $consentReference): void
    {
        if (filled($consentReference)) {
            return;
        }
        foreach ($previous->where('revocable', false) as $old) {
            $still = collect($rows)->first(fn ($r) => $r['designation'] === $old->designation
                && ($old->party_id ? $r['party_id'] === $old->party_id : mb_strtolower($r['full_name']) === mb_strtolower($old->full_name))
                && (float) $r['allocation_pct'] >= (float) $old->allocation_pct);
            if (! $still) {
                throw ValidationException::withMessages(['irrevocable_consent_reference' => ["{$old->full_name} is an irrevocable beneficiary: record their consent before removing or reducing their share."]]);
            }
        }
    }
}
