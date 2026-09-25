<?php

declare(strict_types=1);

use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\PolicyIssuanceRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

if (! function_exists('w1Headers')) {
    function w1Headers(Tenant $t): array
    {
        return ['X-Tenant-Id' => $t->id, 'Idempotency-Key' => (string) Str::uuid()];
    }

    /** Staff user with a tenant role carrying exactly $perms. */
    function w1Staff(Tenant $t, array $perms): User
    {
        $u = User::create(['full_name' => 'W1 Staff '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
        $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'W1_OPS', 'status' => 'ACTIVE']);
        $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'W1_OPS_'.Str::random(6), 'permissions' => $perms, 'is_system' => false])->id);

        return $u;
    }

    /** A fully issued ACTIVE policy (chronology, documents) plus an approved cancellation rule for its line. */
    function w1Policy(): array
    {
        $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
        $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
            'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
            'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null]]],
        ]]);
        $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
        app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
            'payment_reference' => $payment->provider_reference, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'status' => 'SUCCEEDED',
        ], 'sig');
        $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
        $issuer = User::create(['full_name' => 'Issuer', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
        $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-W1'], $issuer);
        if (! DB::table('cancellation_rule_versions')->where('line_code', $f['quote']->line_code)->exists()) {
            DB::table('cancellation_rule_versions')->insert([
                'id' => (string) Str::uuid(), 'line_code' => $f['quote']->line_code, 'version' => 1, 'status' => 'APPROVED', 'basis' => 'PRO_RATA',
                'short_rate_basis_points' => 10000, 'admin_fee_minor' => 0, 'effective_from' => now()->subYear()->toDateString(),
                'effective_until' => null, 'created_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $f;
    }

    function w1Instalment(string $tenantId, string $policyId, string $status = 'DEFAULTED', int $amount = 40000): string
    {
        $id = (string) Str::uuid();
        DB::table('policy_premium_instalments')->insert(['id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $policyId, 'sequence' => random_int(1, 9999),
            'due_date' => now()->subMonth()->toDateString(), 'amount_minor' => $amount, 'currency' => 'XAF', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
