<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\PartyResolver;
use App\Application\Privacy\DataSubjectRequestService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Consent;
use App\Models\DataSubjectRequest;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service account data, always scoped to the caller's own
 * Party (PartyResolver):
 *  - customer profile: address (party_addresses HOME, primary), occupation
 *    and beneficiaries (parties.profile), date of birth
 *    (parties.legal_identity.date_of_birth — the canonical place);
 *  - consents: per-purpose GRANTED/WITHDRAWN rows in `consents` with a
 *    consent_events trail, recorded with notice version + timestamp;
 *  - privacy requests: EXPORT/DELETE feed the Wave 9 DSR workflow
 *    (DataSubjectRequestService::receive -> compliance verifies/resolves).
 */
final class MobileCustomerAccountController
{
    public const CONSENT_PURPOSES = ['MARKETING', 'PARTNER_SHARING', 'ANALYTICS', 'WHATSAPP_UPDATES'];

    private const CURRENT_NOTICE_VERSION = 'privacy-2026-09';

    public function __construct(private PartyResolver $parties, private AuditWriter $audit) {}

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentProfile($this->party($request))]);
    }

    /**
     * PATCH { address_line1?, city?, region?, occupation?, date_of_birth?(Y-m-d),
     *         beneficiaries?: [{name, relationship, share_percent}] (shares sum to 100) }
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_line1' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:120',
            'region' => 'sometimes|nullable|string|max:120',
            'occupation' => 'sometimes|nullable|string|max:120',
            'date_of_birth' => 'sometimes|nullable|date|before:today|after:1900-01-01',
            'beneficiaries' => 'sometimes|array|max:10',
            'beneficiaries.*.name' => 'required|string|min:2|max:160',
            'beneficiaries.*.relationship' => 'required|string|max:64',
            'beneficiaries.*.share_percent' => 'required|numeric|min:0.01|max:100',
        ]);
        if (array_key_exists('beneficiaries', $data) && $data['beneficiaries'] !== []) {
            $total = round(array_sum(array_map(fn ($b) => (float) $b['share_percent'], $data['beneficiaries'])), 2);
            if (abs($total - 100.0) > 0.01) {
                throw ValidationException::withMessages(['beneficiaries' => 'Beneficiary shares must add up to 100%.']);
            }
        }

        $party = $this->party($request);
        DB::transaction(function () use ($party, $data) {
            $profile = $party->profile ?? [];
            foreach (['occupation', 'beneficiaries'] as $k) {
                if (array_key_exists($k, $data)) {
                    $profile[$k] = $k === 'beneficiaries'
                        ? array_map(fn ($b) => ['name' => $b['name'], 'relationship' => strtoupper($b['relationship']), 'share_percent' => round((float) $b['share_percent'], 2)], $data[$k])
                        : $data[$k];
                }
            }
            $identity = $party->legal_identity ?? [];
            if (array_key_exists('date_of_birth', $data)) {
                $identity['date_of_birth'] = $data['date_of_birth'] ? substr((string) $data['date_of_birth'], 0, 10) : null;
            }
            $party->update(['profile' => $profile, 'legal_identity' => $identity]);

            if (array_intersect_key($data, array_flip(['address_line1', 'city', 'region']))) {
                $address = DB::table('party_addresses')->where(['party_id' => $party->id, 'type' => 'HOME'])->first();
                $values = [
                    'line1' => array_key_exists('address_line1', $data) ? $data['address_line1'] : ($address->line1 ?? null),
                    'city' => array_key_exists('city', $data) ? ($data['city'] ?? '') : ($address->city ?? ''),
                    'region' => array_key_exists('region', $data) ? $data['region'] : ($address->region ?? null),
                    'updated_at' => now(),
                ];
                if ($address) {
                    DB::table('party_addresses')->where('id', $address->id)->update($values);
                } else {
                    DB::table('party_addresses')->insert($values + ['id' => (string) Str::uuid(), 'party_id' => $party->id, 'type' => 'HOME', 'country_code' => 'CM', 'is_primary' => true, 'created_at' => now()]);
                }
            }
        });
        $this->audit->record('customer.profile.updated', 'party', $party->id, ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->presentProfile($party->refresh())]);
    }

    public function consents(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentConsents($this->party($request))]);
    }

    /** PUT { notice_version?, consents: [{purpose, granted}] } */
    public function saveConsents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notice_version' => 'nullable|string|max:32',
            'consents' => 'required|array|min:1',
            'consents.*.purpose' => 'required|string|in:'.implode(',', self::CONSENT_PURPOSES),
            'consents.*.granted' => 'required|boolean',
        ]);
        $party = $this->party($request);
        $tenant = app(TenantContext::class)->id();
        $version = $data['notice_version'] ?? self::CURRENT_NOTICE_VERSION;

        DB::transaction(function () use ($data, $party, $tenant, $version, $request) {
            foreach ($data['consents'] as $c) {
                $current = Consent::where('party_id', $party->id)->where('purpose', $c['purpose'])->latest('given_at')->first();
                $wanted = $c['granted'] ? 'GRANTED' : 'WITHDRAWN';
                if ($current && $current->status === $wanted && $current->notice_version === $version) {
                    continue;
                }
                $evidence = ['channel' => 'MOBILE_APP', 'user_id' => $request->user()->id, 'ip_hash' => hash('sha256', (string) $request->ip()), 'at' => now()->toIso8601String()];
                $hash = hash('sha256', json_encode($evidence));
                if ($c['granted']) {
                    $consent = Consent::create(['party_id' => $party->id, 'tenant_id' => $tenant, 'purpose' => $c['purpose'], 'notice_version' => $version, 'status' => 'GRANTED', 'channel' => 'MOBILE_APP', 'captured_by' => $request->user()->id, 'given_at' => now(), 'evidence' => $evidence, 'evidence_hash' => $hash]);
                    $from = $current?->status;
                    if ($current && $current->status === 'GRANTED') {
                        $current->update(['status' => 'WITHDRAWN', 'withdrawn_at' => now()]); // superseded by the new notice version
                    }
                } else {
                    if (! $current || $current->status !== 'GRANTED') {
                        continue; // nothing to withdraw
                    }
                    $current->update(['status' => 'WITHDRAWN', 'withdrawn_at' => now()]);
                    $consent = $current;
                    $from = 'GRANTED';
                }
                DB::table('consent_events')->insert(['id' => (string) Str::uuid(), 'consent_id' => $consent->id, 'from_status' => $from, 'to_status' => $wanted, 'reason_code' => 'CUSTOMER_CHOICE', 'actor_id' => $request->user()->id, 'evidence' => json_encode($evidence), 'evidence_hash' => $hash, 'occurred_at' => now()]);
                $this->audit->record('privacy.consent.'.strtolower($wanted), 'consent', $consent->id, ['purpose' => $c['purpose'], 'notice_version' => $version]);
            }
        });

        return response()->json(['data' => $this->presentConsents($party)]);
    }

    public function privacyRequests(Request $request): JsonResponse
    {
        $party = $this->party($request);
        $rows = DataSubjectRequest::where('party_id', $party->id)->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($x) => $this->presentDsr($x))->values()]);
    }

    /** POST { type: EXPORT|DELETE, notes? } — Idempotency-Key header honoured. */
    public function createPrivacyRequest(Request $request, DataSubjectRequestService $dsr): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:EXPORT,DELETE']);
        $party = $this->party($request);
        $tenant = app(TenantContext::class)->id();
        $type = $data['type'] === 'EXPORT' ? 'PORTABILITY' : 'ERASURE';

        $open = DataSubjectRequest::where('party_id', $party->id)->where('type', $type)->whereNotIn('status', ['COMPLETED', 'REJECTED', 'FULFILLED', 'CLOSED'])->first();
        if ($open) {
            return response()->json(['data' => $this->presentDsr($open)]);
        }

        $key = (string) ($request->header('Idempotency-Key') ?: 'mobile-dsr:'.$party->id.':'.$type.':'.now()->toDateString());
        $x = $dsr->receive($tenant, ['party_id' => $party->id, 'type' => $type, 'due_on' => now()->addDays(30)->toDateString(), 'idempotency_key' => $key], $request->user());
        $this->audit->record('data_subject_request.received', 'data_subject_request', $x->id, ['type' => $type, 'channel' => 'MOBILE']);

        return response()->json(['data' => $this->presentDsr($x)], $x->wasRecentlyCreated ? 201 : 200);
    }

    private function party(Request $request): Party
    {
        $party = $this->parties->forUser($request->user());
        abort_unless($party, 422, 'No customer identity is linked to this account.');

        return $party;
    }

    private function presentProfile(Party $party): array
    {
        $address = DB::table('party_addresses')->where('party_id', $party->id)->orderByDesc('is_primary')->orderByRaw("CASE WHEN type = 'HOME' THEN 0 ELSE 1 END")->first();
        $profile = $party->profile ?? [];

        return [
            'party_id' => $party->id,
            'full_name' => $party->display_name,
            'date_of_birth' => $party->legal_identity['date_of_birth'] ?? null,
            'occupation' => $profile['occupation'] ?? null,
            'address_line1' => $address->line1 ?? null,
            'city' => $address->city ?? null,
            'region' => $address->region ?? null,
            'country_code' => $address->country_code ?? 'CM',
            'beneficiaries' => $profile['beneficiaries'] ?? [],
        ];
    }

    private function presentConsents(Party $party): array
    {
        return array_map(function (string $purpose) use ($party) {
            $c = Consent::where('party_id', $party->id)->where('purpose', $purpose)->latest('given_at')->first();

            return [
                'purpose' => $purpose,
                'granted' => $c?->status === 'GRANTED',
                'notice_version' => $c?->notice_version,
                'updated_at' => ($c?->withdrawn_at ?? $c?->given_at)?->toIso8601String(),
            ];
        }, self::CONSENT_PURPOSES);
    }

    private function presentDsr(DataSubjectRequest $x): array
    {
        return [
            'id' => $x->id,
            'reference' => $x->request_number,
            'type' => $x->type === 'ERASURE' ? 'DELETE' : 'EXPORT',
            'status' => $x->status,
            'due_on' => $x->due_on ? \Carbon\Carbon::parse($x->due_on)->toDateString() : null,
            'created_at' => $x->created_at?->toIso8601String(),
            'completed_at' => $x->completed_at ? \Carbon\Carbon::parse($x->completed_at)->toIso8601String() : null,
        ];
    }
}
