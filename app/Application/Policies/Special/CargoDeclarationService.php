<?php

declare(strict_types=1);

namespace App\Application\Policies\Special;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-011 — cargo declarations under a marine open cover (PRE §66–73; DCP marine cargo: open cover +
 * shipment certificates). Terms come from the OPEN_COVER profile: per_shipment_limit_minor, rate_bps,
 * optional aggregate_limit_minor and conveyances. Premium = insured value × rate (bps), integer minor units.
 * Declarations are immutable; a mistaken one is CANCELLED (never deleted).
 */
final class CargoDeclarationService
{
    public const CONVEYANCES = ['SEA', 'AIR', 'ROAD', 'RAIL', 'MULTIMODAL'];

    public function __construct(
        private readonly SpecialPolicyGuard $guard,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function declare(string $tenantId, string $policyId, array $d, User $actor): array
    {
        $policy = $this->guard->openPolicy($tenantId, $policyId);
        $pr = $this->guard->profile($tenantId, $policyId, 'OPEN_COVER');
        $terms = json_decode($pr->terms, true);
        SpecialPolicyGuard::assertWithinCover($policy, $d['shipment_date'], 'shipment_date');
        $conv = strtoupper($d['conveyance']);
        $allowed = $terms['conveyances'] ?? self::CONVEYANCES;
        if (! in_array($conv, $allowed, true)) {
            throw ValidationException::withMessages(['conveyance' => 'Conveyance not covered by this open cover.']);
        }
        $value = (int) $d['insured_value_minor'];
        if ($value > (int) $terms['per_shipment_limit_minor']) {
            throw ValidationException::withMessages(['insured_value_minor' => 'Insured value exceeds the per-shipment limit; refer to the insurer.']);
        }

        $id = (string) Str::uuid();
        $out = DB::transaction(function () use ($id, $tenantId, $policyId, $pr, $terms, $d, $conv, $value, $actor, $policy) {
            DB::table('special_policy_profiles')->where('id', $pr->id)->lockForUpdate()->first();
            if (isset($terms['aggregate_limit_minor'])) {
                $declared = (int) DB::table('cargo_declarations')->where('profile_id', $pr->id)->where('status', 'DECLARED')->sum('insured_value_minor');
                if ($declared + $value > (int) $terms['aggregate_limit_minor']) {
                    throw ValidationException::withMessages(['insured_value_minor' => 'Declaration would exceed the open cover aggregate limit.']);
                }
            }
            $seq = (int) DB::table('cargo_declarations')->where('profile_id', $pr->id)->max('sequence') + 1;
            $rate = (int) $terms['rate_bps'];
            $premium = intdiv($value * $rate + 5000, 10000);
            $ref = ($policy->policy_number ?: substr($policyId, 0, 8)).'-D'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            DB::table('cargo_declarations')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'profile_id' => $pr->id, 'policy_id' => $policyId, 'sequence' => $seq, 'reference' => $ref,
                'conveyance' => $conv, 'goods_description' => $d['goods_description'], 'origin' => $d['origin'], 'destination' => $d['destination'],
                'shipment_date' => $d['shipment_date'], 'insured_value_minor' => $value, 'rate_bps' => $rate, 'premium_minor' => $premium,
                'currency' => $policy->currency ?? 'XAF', 'status' => 'DECLARED', 'facts' => json_encode((object) ($d['facts'] ?? [])),
                'declared_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('cargo_declaration.declared', 'policy', $policyId, ['declaration_id' => $id, 'reference' => $ref, 'insured_value_minor' => $value, 'premium_minor' => $premium]);
            $this->outbox->record('cargo_declaration.declared', 'policy', $policyId, ['tenant_id' => $tenantId, 'declaration_id' => $id, 'reference' => $ref, 'premium_minor' => $premium]);

            return $id;
        });

        return $this->show($tenantId, $out);
    }

    public function cancel(string $tenantId, string $declarationId, string $reason, User $actor): array
    {
        $dec = DB::table('cargo_declarations')->where('id', $declarationId)->where('tenant_id', $tenantId)->first();
        if (! $dec) {
            abort(404, 'Declaration not found.');
        }
        if ($dec->status !== 'DECLARED') {
            throw ValidationException::withMessages(['declaration' => 'Only a DECLARED declaration can be cancelled.']);
        }
        DB::transaction(function () use ($dec, $reason, $tenantId) {
            DB::table('cargo_declarations')->where('id', $dec->id)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->audit->record('cargo_declaration.cancelled', 'policy', $dec->policy_id, ['declaration_id' => $dec->id, 'reference' => $dec->reference], $reason);
            $this->outbox->record('cargo_declaration.cancelled', 'policy', $dec->policy_id, ['tenant_id' => $tenantId, 'declaration_id' => $dec->id, 'reference' => $dec->reference]);
        });

        return $this->show($tenantId, $dec->id);
    }

    public function list(string $tenantId, string $policyId): array
    {
        $this->guard->policy($tenantId, $policyId);
        $rows = DB::table('cargo_declarations')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->orderBy('sequence')->get();
        $live = $rows->where('status', 'DECLARED');

        return [
            'declarations' => $rows->map(fn ($r) => $this->present($r))->values()->all(),
            'totals' => ['count' => $live->count(), 'insured_value_minor' => (int) $live->sum('insured_value_minor'), 'premium_minor' => (int) $live->sum('premium_minor')],
        ];
    }

    public function show(string $tenantId, string $id): array
    {
        return $this->present(DB::table('cargo_declarations')->where('id', $id)->where('tenant_id', $tenantId)->first() ?? abort(404));
    }

    private function present(object $r): array
    {
        return [
            'id' => $r->id, 'policy_id' => $r->policy_id, 'sequence' => (int) $r->sequence, 'reference' => $r->reference, 'conveyance' => $r->conveyance,
            'goods_description' => $r->goods_description, 'origin' => $r->origin, 'destination' => $r->destination,
            'shipment_date' => \Carbon\CarbonImmutable::parse($r->shipment_date)->toDateString(), 'insured_value_minor' => (int) $r->insured_value_minor,
            'rate_bps' => (int) $r->rate_bps, 'premium_minor' => (int) $r->premium_minor, 'currency' => $r->currency, 'status' => $r->status,
            'cancel_reason' => $r->cancel_reason,
        ];
    }
}
