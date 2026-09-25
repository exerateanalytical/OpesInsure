<?php

declare(strict_types=1);

namespace App\Application\Rating;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Domain\Rating\DeterministicRatingEngine;
use App\Domain\Rating\TariffLifecycle;
use App\Models\InsuranceProduct;
use App\Models\TariffVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RAT-002 — tariff versions, PRE §75: DRAFT → IN_REVIEW → APPROVED (maker-checker, hash, overlap guard)
 * → SCHEDULED → ACTIVE → EXPIRED, plus REJECTED. Immutable history in tariff_status_history.
 */
final class TariffGovernanceService
{
    public function __construct(private readonly CanonicalJson $json, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function create(InsuranceProduct $p, array $data, User $actor): TariffVersion
    {
        return DB::transaction(function () use ($p, $data, $actor) {
            $this->validateRules($data['rules']);
            // Postgres refuses FOR UPDATE with an aggregate: serialise on the product row instead.
            InsuranceProduct::whereKey($p->id)->lockForUpdate()->first();
            $version = (TariffVersion::where('insurance_product_id', $p->id)->max('version') ?? 0) + 1;
            $t = TariffVersion::create(['insurance_product_id' => $p->id, 'version' => $version, 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'status' => 'DRAFT', 'input_schema' => $data['input_schema'], 'rules' => $data['rules'], 'rules_hash' => $this->json->hash($data['rules']),
                'created_by' => $actor->id, 'regulatory_reference' => $data['regulatory_reference']]);
            $this->history($t, null, 'DRAFT', 'CREATED', 'Tariff version created.', $actor);
            $this->audit->record('tariff.created', 'tariff_version', $t->id, ['version' => $version]);

            return $t;
        });
    }

    public function submit(TariffVersion $t, User $actor, string $notes): TariffVersion
    {
        $t = $this->transition($t, 'submit', 'SUBMITTED_FOR_REVIEW', $notes, $actor);
        $this->audit->record('tariff.submitted', 'tariff_version', $t->id, ['version' => $t->version]);
        $this->outbox->record('tariff.submitted', 'tariff_version', $t->id, ['tariff_id' => $t->id]);

        return $t;
    }

    public function reject(TariffVersion $t, User $actor, string $reason): TariffVersion
    {
        $this->checker($t, $actor);
        $t = $this->transition($t, 'reject', 'REJECTED', $reason, $actor);
        $this->audit->record('tariff.rejected', 'tariff_version', $t->id, ['version' => $t->version]);

        return $t;
    }

    public function approve(TariffVersion $t, User $actor, string $reason): TariffVersion
    {
        if ($t->status !== 'IN_REVIEW') {
            throw ValidationException::withMessages(['status' => __('wave2.tariff_not_reviewable')]);
        }
        $this->checker($t, $actor);
        if ($this->json->hash($t->rules) !== $t->rules_hash) {
            throw ValidationException::withMessages(['rules' => __('wave2.tariff_hash_mismatch')]);
        }
        $this->validateRules($t->rules);
        $this->guardOverlap($t);

        return DB::transaction(function () use ($t, $actor, $reason) {
            $t->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_reason' => $reason]);
            $this->history($t, 'IN_REVIEW', 'APPROVED', 'APPROVED', $reason, $actor);
            $this->audit->record('tariff.approved', 'tariff_version', $t->id, ['rules_hash' => $t->rules_hash], 'APPROVED_TARIFF');
            $this->outbox->record('tariff.approved', 'tariff_version', $t->id, ['tariff_id' => $t->id, 'product_id' => $t->insurance_product_id, 'rules_hash' => $t->rules_hash]);

            return $t->refresh();
        });
    }

    /** APPROVED → SCHEDULED (future-dated) or straight to ACTIVE when already effective. */
    public function schedule(TariffVersion $t, User $actor, string $notes): TariffVersion
    {
        if ($t->effective_from->toDateString() <= now()->toDateString()) {
            return $this->activate($t, $actor, $notes);
        }
        $t = $this->transition($t, 'schedule', 'SCHEDULED', $notes, $actor, ['scheduled_at' => now()]);
        $this->audit->record('tariff.scheduled', 'tariff_version', $t->id, ['effective_from' => $t->effective_from->toDateString()]);
        $this->outbox->record('tariff.scheduled', 'tariff_version', $t->id, ['tariff_id' => $t->id]);

        return $t;
    }

    public function activate(TariffVersion $t, ?User $actor, string $notes): TariffVersion
    {
        if ($t->effective_from->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => __('rating.tariff_not_yet_effective')]);
        }

        return DB::transaction(function () use ($t, $actor, $notes) {
            // One ACTIVE version per product: the predecessor expires the day before (non-destructive, rows kept).
            foreach (TariffVersion::where('insurance_product_id', $t->insurance_product_id)->where('status', 'ACTIVE')->where('id', '!=', $t->id)->lockForUpdate()->get() as $old) {
                $until = $old->effective_until && $old->effective_until->toDateString() < $t->effective_from->toDateString() ? $old->effective_until : $t->effective_from->copy()->subDay();
                $this->transition($old, 'expire', 'SUPERSEDED', "Superseded by version {$t->version}.", $actor, ['expired_at' => now(), 'effective_until' => $until->toDateString()]);
            }
            $t = $this->transition($t, 'activate', 'ACTIVATED', $notes, $actor, ['activated_at' => now()]);
            $this->audit->record('tariff.activated', 'tariff_version', $t->id, ['version' => $t->version]);
            $this->outbox->record('tariff.activated', 'tariff_version', $t->id, ['tariff_id' => $t->id, 'product_id' => $t->insurance_product_id]);

            return $t;
        });
    }

    /** Close the window at $until (default today); history for past dates keeps rating reproducibly. */
    public function expire(TariffVersion $t, ?User $actor, string $notes, ?string $until = null): TariffVersion
    {
        $until ??= $t->effective_until && $t->effective_until->isPast() ? $t->effective_until->toDateString() : now()->toDateString();
        if ($until < $t->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_until' => __('rating.tariff_until_before_from')]);
        }
        $t = $this->transition($t, 'expire', 'EXPIRED', $notes, $actor, ['expired_at' => now(), 'effective_until' => $until]);
        $this->audit->record('tariff.expired', 'tariff_version', $t->id, ['effective_until' => $until]);
        $this->outbox->record('tariff.expired', 'tariff_version', $t->id, ['tariff_id' => $t->id]);

        return $t;
    }

    /** Scheduler sweep (tariffs:advance): SCHEDULED whose date arrived → ACTIVE; ACTIVE past effective_until → EXPIRED. */
    public function advanceDue(): array
    {
        $today = now()->toDateString();
        $activated = $expired = 0;
        foreach (TariffVersion::where('status', 'SCHEDULED')->whereDate('effective_from', '<=', $today)->orderBy('effective_from')->get() as $t) {
            $this->activate($t, null, 'Effective date reached.');
            $activated++;
        }
        foreach (TariffVersion::where('status', 'ACTIVE')->whereNotNull('effective_until')->whereDate('effective_until', '<', $today)->get() as $t) {
            $this->expire($t, null, 'Effective window ended.', $t->effective_until->toDateString());
            $expired++;
        }

        return ['activated' => $activated, 'expired' => $expired];
    }

    public function validateRules(array $r): void
    {
        $engine = new DeterministicRatingEngine;
        if (isset($r['base'])) {
            $this->validateMethod($r['base'], 'rules.base');
        } elseif ((int) ($r['base_premium_minor'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['rules' => __('wave2.positive_base_required')]);
        }
        foreach (['factors', 'loadings'] as $group) {
            foreach ($r[$group] ?? [] as $i => $f) {
                if (! isset($f['code'], $f['fact'], $f['operator'], $f['value']) || (! isset($f['basis_points']) && ! isset($f['fixed_minor']))) {
                    throw ValidationException::withMessages(["rules.$group.$i" => __('wave2.factor_incomplete')]);
                }
                if (! in_array($f['operator'], ['EQUALS', 'IN', 'BETWEEN'], true) || (isset($f['basis_points']) && ((int) $f['basis_points'] < -10000 || (int) $f['basis_points'] > 100000))
                    || (isset($f['type']) && ! in_array($f['type'], $engine::LOADING_TYPES, true))) {
                    throw ValidationException::withMessages(["rules.$group.$i" => __('wave2.factor_invalid')]);
                }
            }
        }
        foreach ($r['discounts'] ?? [] as $i => $d) {
            if (! isset($d['code'], $d['type'], $d['basis_points']) || ! in_array($d['type'], $engine::DISCOUNT_TYPES, true) || (int) $d['basis_points'] < 0 || (int) $d['basis_points'] > 10000) {
                throw ValidationException::withMessages(["rules.discounts.$i" => __('rating.discount_invalid')]);
            }
        }
        foreach ($r['coverages'] ?? [] as $i => $c) {
            if (! isset($c['code'])) {
                throw ValidationException::withMessages(["rules.coverages.$i" => __('rating.method_invalid')]);
            }
            $this->validateMethod($c, "rules.coverages.$i");
        }
        if (isset($r['rounding']) && ! in_array($r['rounding']['mode'] ?? 'HALF_UP', ['HALF_UP', 'UP', 'DOWN'], true)) {
            throw ValidationException::withMessages(['rules.rounding' => __('rating.rounding_invalid')]);
        }
        if (isset($r['branch_allocation']) && array_sum(array_map(fn ($s) => (int) ($s['basis_points'] ?? 0), $r['branch_allocation'])) !== 10000) {
            throw ValidationException::withMessages(['rules.branch_allocation' => __('rating.allocation_invalid')]);
        }
    }

    private function validateMethod(array $m, string $path): void
    {
        $method = $m['method'] ?? 'FIXED';
        $required = match ($method) {
            'FIXED' => ['amount_minor'], 'RATE_X_SUM_INSURED', 'RATE_X_LIMIT' => ['rate_ppm'], 'TABLE' => ['keys', 'rows'], 'BAND' => ['fact', 'bands'],
            'TIER' => ['fact', 'tiers'], 'FORMULA' => ['terms'], 'PER_PERSON', 'PER_VEHICLE', 'PER_EMPLOYEE', 'PER_DAY', 'PER_TRIP', 'PER_SHIPMENT' => ['unit_amount_minor'],
            default => null,
        };
        if ($required === null) {
            throw ValidationException::withMessages([$path => __('rating.method_invalid')]);
        }
        foreach ($required as $k) {
            if (! array_key_exists($k, $m)) {
                throw ValidationException::withMessages([$path => __('rating.method_invalid')]);
            }
        }
    }

    private function checker(TariffVersion $t, User $actor): void
    {
        if ($t->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
        }
    }

    private function guardOverlap(TariffVersion $t): void
    {
        $overlap = TariffVersion::where('insurance_product_id', $t->insurance_product_id)->whereIn('status', TariffLifecycle::OCCUPYING)->where('id', '!=', $t->id)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $t->effective_from))
            ->where(fn ($q) => $q->whereDate('effective_from', '<=', $t->effective_until ?? '9999-12-31'))
            // An open-ended ACTIVE predecessor starting earlier is closed on activation, so it does not block approval.
            ->where(fn ($q) => $q->where('status', '!=', 'ACTIVE')->orWhereNotNull('effective_until')->orWhereDate('effective_from', '>=', $t->effective_from))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['effective_from' => __('wave2.tariff_overlap')]);
        }
    }

    private function transition(TariffVersion $t, string $event, string $reason, string $notes, ?User $actor, array $extra = []): TariffVersion
    {
        $from = $t->status;
        try {
            $to = TariffLifecycle::target($event, $from);
        } catch (DomainException) {
            throw ValidationException::withMessages(['status' => __('wave2.invalid_tariff_transition')]);
        }
        $t->forceFill(['status' => $to, ...$extra])->save();
        $this->history($t, $from, $to, $reason, $notes, $actor);

        return $t->refresh();
    }

    private function history(TariffVersion $t, ?string $from, string $to, string $reason, string $notes, ?User $actor): void
    {
        DB::table('tariff_status_history')->insert(['id' => (string) Str::uuid(), 'tariff_version_id' => $t->id, 'from_status' => $from, 'to_status' => $to,
            'reason_code' => $reason, 'notes' => $notes, 'actor_id' => $actor?->id ?? $this->systemActor(), 'occurred_at' => now()]);
    }

    /** tariff_status_history.actor_id is NOT NULL; scheduler transitions are attributed to the platform's first user (system). */
    private function systemActor(): string
    {
        return (string) User::orderBy('created_at')->value('id');
    }
}
