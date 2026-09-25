<?php

declare(strict_types=1);

namespace App\Application\Documents\Signatures;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\DocumentGovernanceProblem;
use App\Application\Events\OutboxWriter;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-DOC-012 signature requests and signature records.
 *  - the request pins the document hash: a signer can only sign the exact bytes that were sent for signature;
 *  - signers act in signing_order; each act is stored with its evidence and a signature_hash binding
 *    request, signer, user, document hash and time;
 *  - one decline ends the request (DECLINED); all signed -> COMPLETED.
 */
final class SignatureService
{
    /** @var array<string, SignatureProvider> */
    private array $providers = [];

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox, ManualClickToSignProvider $manual)
    {
        $this->register($manual);
    }

    public function register(SignatureProvider $p): void
    {
        $this->providers[$p->code()] = $p;
    }

    public function provider(string $code): SignatureProvider
    {
        return $this->providers[$code] ?? throw DocumentGovernanceProblem::make('UNKNOWN_PROVIDER', 422, "No signature provider {$code}.");
    }

    /** @param array{document_id: string, consent_text: string, provider?: string, expires_at?: ?string, signers: list<array{user_id?: ?string, party_id?: ?string, name: string, role: string, order?: int}>} $d */
    public function request(string $tenantId, array $d, User $actor): array
    {
        $doc = Document::where('tenant_id', $tenantId)->whereKey($d['document_id'])->first();
        if (! $doc || DB::table('documents')->where('id', $doc->id)->value('destroyed_at') !== null) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Document not found.');
        }
        if (in_array($doc->status, ['REVOKED', 'CANCELLED', 'SUPERSEDED', 'REPLACED', 'EXPIRED'], true)) {
            throw DocumentGovernanceProblem::make('DOCUMENT_NOT_CURRENT', 409, 'Only a current document can be sent for signature.');
        }
        foreach ($d['signers'] as $s) {
            if (empty($s['user_id']) && empty($s['party_id'])) {
                throw DocumentGovernanceProblem::make('SIGNER_IDENTITY_REQUIRED', 422, 'Each signer needs a user_id or party_id.');
            }
        }
        $provider = $this->provider($d['provider'] ?? 'MANUAL');
        $id = (string) Str::uuid();

        DB::transaction(function () use ($id, $tenantId, $d, $doc, $actor, $provider) {
            DB::table('signature_requests')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'document_id' => $doc->id, 'provider' => $provider->code(), 'status' => 'PENDING',
                'document_sha256' => $doc->sha256, 'consent_text' => $d['consent_text'], 'expires_at' => $d['expires_at'] ?? null,
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_values($d['signers']) as $i => $s) {
                DB::table('signature_request_signers')->insert([
                    'id' => (string) Str::uuid(), 'signature_request_id' => $id, 'signer_user_id' => $s['user_id'] ?? null, 'signer_party_id' => $s['party_id'] ?? null,
                    'signer_name' => $s['name'], 'signer_role' => $s['role'], 'signing_order' => (int) ($s['order'] ?? $i + 1),
                    'status' => 'PENDING', 'evidence' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $ref = $provider->initiate(DB::table('signature_requests')->find($id), $this->signers($id));
            if ($ref !== null) {
                DB::table('signature_requests')->where('id', $id)->update(['provider_reference' => $ref]);
            }
            $this->audit->record('document.signature.requested', 'signature_request', $id, ['document_id' => $doc->id, 'signers' => count($d['signers'])]);
            $this->outbox->record('document.signature.requested', 'signature_request', $id, ['document_id' => $doc->id, 'provider' => $provider->code(), 'signers' => count($d['signers'])]);
        });

        return $this->show($id);
    }

    /** @param array{ip?: ?string, user_agent?: ?string, consent_accepted: bool} $context */
    public function sign(string $requestId, User $user, array $context): array
    {
        return DB::transaction(function () use ($requestId, $user, $context) {
            [$req, $signer] = $this->actionable($requestId, $user);
            $currentHash = DB::table('documents')->where('id', $req->document_id)->value('sha256');
            if ($currentHash !== $req->document_sha256) {
                throw DocumentGovernanceProblem::make('DOCUMENT_CHANGED', 409, 'The document changed after it was sent for signature.');
            }
            $captured = $this->provider($req->provider)->capture($req, $signer, $user, $context);
            $at = now();
            DB::table('signature_request_signers')->where('id', $signer->id)->update([
                'status' => 'SIGNED', 'method' => $captured['method'], 'evidence' => json_encode($captured['evidence'], JSON_THROW_ON_ERROR),
                'signature_hash' => hash('sha256', implode('|', [$req->id, $signer->id, $user->id, $req->document_sha256, $at->toIso8601String()])),
                'acted_at' => $at, 'updated_at' => $at,
            ]);
            $this->outbox->record('document.signature.signed', 'signature_request', $req->id, ['document_id' => $req->document_id, 'signer_id' => $signer->id, 'method' => $captured['method']]);
            if (! DB::table('signature_request_signers')->where('signature_request_id', $req->id)->where('status', '<>', 'SIGNED')->exists()) {
                DB::table('signature_requests')->where('id', $req->id)->update(['status' => 'COMPLETED', 'completed_at' => $at, 'updated_at' => $at]);
                $this->audit->record('document.signature.completed', 'signature_request', $req->id, ['document_id' => $req->document_id]);
                $this->outbox->record('document.signature.completed', 'signature_request', $req->id, ['document_id' => $req->document_id]);
            }

            return $this->show($req->id);
        });
    }

    public function decline(string $requestId, User $user, string $reason): array
    {
        return DB::transaction(function () use ($requestId, $user, $reason) {
            [$req, $signer] = $this->actionable($requestId, $user);
            DB::table('signature_request_signers')->where('id', $signer->id)->update(['status' => 'DECLINED', 'evidence' => json_encode(['reason' => $reason, 'authenticated_user_id' => $user->id]), 'acted_at' => now(), 'updated_at' => now()]);
            DB::table('signature_requests')->where('id', $req->id)->update(['status' => 'DECLINED', 'completed_at' => now(), 'updated_at' => now()]);
            $this->audit->record('document.signature.declined', 'signature_request', $req->id, ['signer_id' => $signer->id], $reason);
            $this->outbox->record('document.signature.declined', 'signature_request', $req->id, ['document_id' => $req->document_id, 'signer_id' => $signer->id]);

            return $this->show($req->id);
        });
    }

    public function cancel(string $tenantId, string $requestId, User $actor, string $reason): array
    {
        $req = DB::table('signature_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->first()
            ?? throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Signature request not found.');
        if ($req->status !== 'PENDING') {
            throw DocumentGovernanceProblem::make('NOT_PENDING', 409, 'Signature request is '.$req->status.'.');
        }
        DB::table('signature_requests')->where('id', $req->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        $this->audit->record('document.signature.cancelled', 'signature_request', $req->id, [], $reason);

        return $this->show($req->id);
    }

    public function show(string $id): array
    {
        $req = DB::table('signature_requests')->find($id) ?? throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Signature request not found.');

        return (array) $req + ['signers' => array_map(function ($s) {
            $s->evidence = json_decode((string) $s->evidence, true);

            return $s;
        }, $this->signers($id))];
    }

    /** @return list<object> */
    private function signers(string $requestId): array
    {
        return DB::table('signature_request_signers')->where('signature_request_id', $requestId)->orderBy('signing_order')->get()->all();
    }

    /** @return array{0: object, 1: object} */
    private function actionable(string $requestId, User $user): array
    {
        $req = DB::table('signature_requests')->where('id', $requestId)->lockForUpdate()->first();
        if (! $req) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Signature request not found.');
        }
        $signer = DB::table('signature_request_signers')->where('signature_request_id', $req->id)->where('status', 'PENDING')
            ->where(fn ($q) => $q->where('signer_user_id', $user->id)->when($user->party_id, fn ($w) => $w->orWhere('signer_party_id', $user->party_id)))
            ->orderBy('signing_order')->first();
        if (! $signer) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'No pending signature for this user on this request.');
        }
        if ($req->status !== 'PENDING') {
            throw DocumentGovernanceProblem::make('NOT_PENDING', 409, 'Signature request is '.$req->status.'.');
        }
        if ($req->expires_at !== null && now()->greaterThan($req->expires_at)) {
            throw DocumentGovernanceProblem::make('EXPIRED', 409, 'Signature request expired.');
        }
        if (DB::table('signature_request_signers')->where('signature_request_id', $req->id)->where('status', 'PENDING')->where('signing_order', '<', $signer->signing_order)->exists()) {
            throw DocumentGovernanceProblem::make('OUT_OF_ORDER', 409, 'An earlier signer has not signed yet.');
        }

        return [$req, $signer];
    }
}
