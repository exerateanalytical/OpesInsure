<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Certificates;

use App\Models\Policy;
use App\Models\PolicyCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public "is this vehicle/holder insured?" check by reference alone — the
 * number printed on a certificate or sticker. Unlike
 * POST public/certificates/verify it needs no verification token, so it
 * deliberately discloses only what a roadside check needs: validity,
 * insurer, product class and the coverage window. Never the holder's name,
 * premium or any personal data. Rate limited at the route.
 *
 * The reference is matched against, in order: a certificate serial number,
 * a policy's certificate_number, a policy_number.
 */
final class PublicInsuranceVerifyController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|min:3|max:100']);
        $reference = trim($data['reference']);

        $certificate = PolicyCertificate::with('policy')->where('serial_number', $reference)->first();
        $policy = $certificate?->policy
            ?? Policy::where('certificate_number', $reference)->first()
            ?? Policy::where('policy_number', $reference)->first();

        if (! $policy) {
            return response()->json(['data' => ['result' => 'not_found', 'reference' => $reference, 'carrier_name' => null, 'product_class' => null, 'product_name' => null, 'policy_status' => null, 'coverage_starts_at' => null, 'coverage_ends_at' => null, 'checked_at' => now()->toIso8601String()]]);
        }

        $policy->loadMissing(['carrier.party', 'proposal.offer.product']);
        $result = $this->resultFor($policy, $certificate);

        if ($certificate) {
            DB::table('certificate_verification_events')->insert([
                'id' => (string) Str::uuid(), 'policy_certificate_id' => $certificate->id,
                'result' => $result === 'valid' ? 'VERIFIED' : 'REJECTED',
                'request_fingerprint_hash' => hash('sha256', $request->ip().'|'.$request->userAgent()), 'occurred_at' => now(),
            ]);
        }

        $product = $policy->proposal?->offer?->product;

        return response()->json(['data' => [
            'result' => $result,
            'reference' => $reference,
            'carrier_name' => $policy->carrier?->party?->display_name,
            'product_class' => $product?->line_code,
            'product_name' => $product?->name,
            'policy_status' => $policy->status,
            'coverage_starts_at' => $policy->coverage_starts_at?->toIso8601String(),
            'coverage_ends_at' => $policy->coverage_ends_at?->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /** valid | expired | not_yet_active | suspended | cancelled | invalid */
    private function resultFor(Policy $policy, ?PolicyCertificate $certificate): string
    {
        if ($certificate && $certificate->status !== 'VALID') {
            return 'invalid';
        }

        return match (true) {
            $policy->status === 'SUSPENDED' => 'suspended',
            in_array($policy->status, ['CANCELLED', 'VOID', 'LAPSED'], true) => 'cancelled',
            $policy->status === 'EXPIRED' || $policy->coverage_ends_at?->isPast() => 'expired',
            $policy->coverage_starts_at?->isFuture() => 'not_yet_active',
            $policy->status === 'ACTIVE' => 'valid',
            default => 'invalid',
        };
    }
}
