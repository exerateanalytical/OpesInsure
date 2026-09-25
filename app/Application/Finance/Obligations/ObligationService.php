<?php

declare(strict_types=1);

namespace App\Application\Finance\Obligations;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-OBL-001 — universal financial obligations (the money-chain contract).
 *
 *   create      — one RECEIVABLE / PAYABLE per money fact; idempotent on (source_type, source_id, type, source_reference).
 *   settle      — applies an amount (integer minor units) against the outstanding; idempotent per reference.
 *   unsettle    — reverses a settlement (e.g. an allocation reversal / charge-back): outstanding grows back, status reopens.
 *   writeOff / cancel — terminal, with history.
 *   outstanding — the open balance.
 *   aging       — CURRENT / 1_30 / 31_60 / 61_90 / 90_PLUS from due_at.
 * Every change appends to financial_obligation_events.
 */
final class ObligationService
{
    public const KINDS = ['RECEIVABLE', 'PAYABLE'];

    public const TYPES = ['PREMIUM', 'INSTALMENT', 'FEE', 'TAX', 'REFUND', 'COMMISSION', 'CLAIM', 'OTHER'];

    public const OPEN_STATUSES = ['OPEN', 'PARTIALLY_SETTLED'];

    public const BUCKETS = ['CURRENT', '1_30', '31_60', '61_90', '90_PLUS'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /**
     * @param array{tenant_id:string, kind:string, type:string, source_type:string, source_id:string, currency:string, amount_minor:int,
     *              due_at:mixed, debtor_type?:?string, debtor_id?:?string, creditor_type?:?string, creditor_id?:?string,
     *              policy_id?:?string, source_reference?:?string, description?:?string, metadata?:?array} $d
     */
    public function create(array $d, ?string $actorId = null): object
    {
        if (! in_array($d['kind'], self::KINDS, true)) {
            $this->fail('kind', 'KIND_INVALID', "Unknown obligation kind {$d['kind']}.");
        }
        if (! in_array($d['type'], self::TYPES, true)) {
            $this->fail('type', 'TYPE_INVALID', "Unknown obligation type {$d['type']}.");
        }
        if ((int) $d['amount_minor'] <= 0) {
            $this->fail('amount_minor', 'AMOUNT_INVALID', 'Obligation amount must be positive.');
        }
        if (! preg_match('/^[A-Z]{3}$/', (string) $d['currency'])) {
            $this->fail('currency', 'CURRENCY_INVALID', 'Currency must be an ISO 4217 code.');
        }

        return DB::transaction(function () use ($d, $actorId): object {
            $existing = DB::table('financial_obligations')->where(['source_type' => $d['source_type'], 'source_id' => $d['source_id'], 'type' => $d['type']])
                ->when($d['source_reference'] ?? null, fn ($q, $r) => $q->where('source_reference', $r), fn ($q) => $q->whereNull('source_reference'))->first();
            if ($existing) {
                return $existing;
            }
            $id = (string) Str::uuid();
            $amount = (int) $d['amount_minor'];
            DB::table('financial_obligations')->insert([
                'id' => $id, 'tenant_id' => $d['tenant_id'], 'kind' => $d['kind'], 'type' => $d['type'],
                'debtor_type' => $d['debtor_type'] ?? null, 'debtor_id' => $d['debtor_id'] ?? null,
                'creditor_type' => $d['creditor_type'] ?? null, 'creditor_id' => $d['creditor_id'] ?? null,
                'source_type' => $d['source_type'], 'source_id' => $d['source_id'], 'source_reference' => $d['source_reference'] ?? null, 'policy_id' => $d['policy_id'] ?? null,
                'currency' => $d['currency'], 'amount_minor' => $amount, 'outstanding_minor' => $amount,
                'due_at' => CarbonImmutable::parse($d['due_at']), 'status' => 'OPEN', 'description' => $d['description'] ?? null,
                'metadata' => isset($d['metadata']) ? json_encode($d['metadata']) : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->history($id, 'CREATED', $amount, $amount, 'OPEN', null, $actorId);
            $this->outbox->record('finance.obligation.created', 'financial_obligation', $id, [
                'obligation_id' => $id, 'kind' => $d['kind'], 'type' => $d['type'], 'source_type' => $d['source_type'], 'source_id' => $d['source_id'],
                'amount_minor' => $amount, 'currency' => $d['currency'],
            ]);

            return DB::table('financial_obligations')->where('id', $id)->first();
        });
    }

    /** Apply a settlement. Idempotent per (obligation, reference); an over-payment settles the obligation and is capped. */
    public function settle(string $obligationId, int $amountMinor, ?string $reference = null, ?string $actorId = null): object
    {
        if ($amountMinor <= 0) {
            $this->fail('amount_minor', 'AMOUNT_INVALID', 'Settlement amount must be positive.');
        }

        return DB::transaction(function () use ($obligationId, $amountMinor, $reference, $actorId): object {
            $o = $this->locked($obligationId);
            if ($reference !== null && DB::table('financial_obligation_events')->where(['financial_obligation_id' => $o->id, 'event_type' => 'SETTLEMENT', 'reference' => $reference])->exists()) {
                return $o;
            }
            if (! in_array($o->status, self::OPEN_STATUSES, true)) {
                return $o;
            }
            $applied = min($amountMinor, (int) $o->outstanding_minor);
            $left = (int) $o->outstanding_minor - $applied;
            $status = $left === 0 ? 'SETTLED' : 'PARTIALLY_SETTLED';
            DB::table('financial_obligations')->where('id', $o->id)->update([
                'outstanding_minor' => $left, 'status' => $status, 'updated_at' => now(),
            ] + ($left === 0 ? ['settled_at' => now()] : []));
            $this->history($o->id, 'SETTLEMENT', $applied, $left, $status, $reference, $actorId, $amountMinor > $applied ? ['excess_minor' => $amountMinor - $applied] : null);
            if ($left === 0) {
                $this->outbox->record('finance.obligation.settled', 'financial_obligation', $o->id, [
                    'obligation_id' => $o->id, 'type' => $o->type, 'source_type' => $o->source_type, 'source_id' => $o->source_id,
                    'amount_minor' => (int) $o->amount_minor, 'currency' => $o->currency, 'reference' => $reference,
                ]);
            }

            return DB::table('financial_obligations')->where('id', $o->id)->first();
        });
    }

    /** Reverse (part of) a settlement. Idempotent per (obligation, reference); never exceeds the settled amount. */
    public function unsettle(string $obligationId, int $amountMinor, ?string $reference = null, ?string $actorId = null): object
    {
        if ($amountMinor <= 0) {
            $this->fail('amount_minor', 'AMOUNT_INVALID', 'Reversal amount must be positive.');
        }

        return DB::transaction(function () use ($obligationId, $amountMinor, $reference, $actorId): object {
            $o = $this->locked($obligationId);
            if ($reference !== null && DB::table('financial_obligation_events')->where(['financial_obligation_id' => $o->id, 'event_type' => 'UNSETTLEMENT', 'reference' => $reference])->exists()) {
                return $o;
            }
            if (in_array($o->status, ['WRITTEN_OFF', 'CANCELLED'], true)) {
                $this->fail('status', 'OBLIGATION_CLOSED', "Obligation is {$o->status}.");
            }
            $reversed = min($amountMinor, (int) $o->amount_minor - (int) $o->outstanding_minor);
            if ($reversed <= 0) {
                return $o;
            }
            $left = (int) $o->outstanding_minor + $reversed;
            $status = $left === (int) $o->amount_minor ? 'OPEN' : 'PARTIALLY_SETTLED';
            DB::table('financial_obligations')->where('id', $o->id)->update(['outstanding_minor' => $left, 'status' => $status, 'settled_at' => null, 'updated_at' => now()]);
            $this->history($o->id, 'UNSETTLEMENT', $reversed, $left, $status, $reference, $actorId);
            $this->outbox->record('finance.obligation.reopened', 'financial_obligation', $o->id, [
                'obligation_id' => $o->id, 'reversed_minor' => $reversed, 'outstanding_minor' => $left, 'currency' => $o->currency, 'reference' => $reference,
            ]);

            return DB::table('financial_obligations')->where('id', $o->id)->first();
        });
    }

    public function writeOff(string $obligationId, string $reason, ?string $actorId = null): object
    {
        return $this->close($obligationId, 'WRITTEN_OFF', $reason, $actorId);
    }

    public function cancel(string $obligationId, string $reason, ?string $actorId = null): object
    {
        return $this->close($obligationId, 'CANCELLED', $reason, $actorId);
    }

    public function outstanding(string $obligationId): int
    {
        return (int) DB::table('financial_obligations')->where('id', $obligationId)->value('outstanding_minor');
    }

    public static function bucket(\DateTimeInterface|string $dueAt, ?\DateTimeInterface $asOf = null): string
    {
        $days = (int) CarbonImmutable::parse($dueAt)->startOfDay()->diffInDays(CarbonImmutable::instance($asOf ?? now())->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'CURRENT',
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => '90_PLUS',
        };
    }

    /** @return array<string, array<string, int>> currency => bucket => outstanding_minor */
    public function aging(string $tenantId, ?string $kind = null, ?\DateTimeInterface $asOf = null): array
    {
        $out = [];
        DB::table('financial_obligations')->where('tenant_id', $tenantId)->whereIn('status', self::OPEN_STATUSES)
            ->when($kind, fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('id')->select(['currency', 'due_at', 'outstanding_minor'])->chunk(500, function ($rows) use (&$out, $asOf): void {
                foreach ($rows as $r) {
                    $out[$r->currency] ??= array_fill_keys(self::BUCKETS, 0);
                    $out[$r->currency][self::bucket($r->due_at, $asOf)] += (int) $r->outstanding_minor;
                }
            });

        return $out;
    }

    private function close(string $obligationId, string $status, string $reason, ?string $actorId): object
    {
        return DB::transaction(function () use ($obligationId, $status, $reason, $actorId): object {
            $o = $this->locked($obligationId);
            if (! in_array($o->status, self::OPEN_STATUSES, true)) {
                $this->fail('status', 'OBLIGATION_CLOSED', "Obligation is {$o->status}.");
            }
            DB::table('financial_obligations')->where('id', $o->id)->update(['status' => $status, 'updated_at' => now()]);
            $this->history($o->id, $status, (int) $o->outstanding_minor, (int) $o->outstanding_minor, $status, null, $actorId, ['reason' => $reason]);
            $this->audit->record('finance.obligation.'.strtolower($status), 'financial_obligation', $o->id, ['outstanding_minor' => (int) $o->outstanding_minor], $reason);

            return DB::table('financial_obligations')->where('id', $o->id)->first();
        });
    }

    private function locked(string $id): object
    {
        return DB::table('financial_obligations')->where('id', $id)->lockForUpdate()->first()
            ?? $this->fail('obligation_id', 'OBLIGATION_NOT_FOUND', 'Obligation not found.');
    }

    private function history(string $id, string $type, int $amount, int $after, string $status, ?string $reference, ?string $actorId, ?array $meta = null): void
    {
        DB::table('financial_obligation_events')->insert([
            'id' => (string) Str::uuid(), 'financial_obligation_id' => $id, 'event_type' => $type, 'amount_minor' => $amount,
            'outstanding_after_minor' => $after, 'status_after' => $status, 'reference' => $reference, 'actor_id' => $actorId,
            'metadata' => $meta ? json_encode($meta) : null, 'occurred_at' => now(),
        ]);
    }

    private function fail(string $field, string $code, string $message): never
    {
        throw ValidationException::withMessages([$field => "{$code}: {$message}"]);
    }
}
