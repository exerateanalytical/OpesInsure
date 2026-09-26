<?php

declare(strict_types=1);

namespace App\Application\PrivateOnboarding;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Review of staged private onboarding records (maker-checker: the uploader never reviews) and the per-organization
 * readiness view the insurer / broker setup checklists read. Acceptance records that the source evidence was checked; it
 * does not turn on any workflow. The only promotion done here is SIGNATORY_MANDATES => signatory_authorities (the registry
 * this pack introduced), and even then the authority stays PENDING_VERIFICATION until the document engine verifies it.
 */
final class PrivateOnboardingService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function review(string $id, string $decision, ?string $note, User $actor, ?string $tenantId): object
    {
        if (! in_array($decision, ['ACCEPTED', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['decision' => 'decision must be ACCEPTED or REJECTED.']);
        }
        if ($decision === 'REJECTED' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'A note is required to reject a record.']);
        }

        return DB::transaction(function () use ($id, $decision, $note, $actor, $tenantId) {
            $r = DB::table('tenant_onboarding_records')->where('id', $id)->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->lockForUpdate()->first();
            if (! $r) {
                abort(404);
            }
            if ($r->review_status !== 'RECEIVED') {
                throw ValidationException::withMessages(['record' => 'Only a RECEIVED record can be reviewed.']);
            }
            if ($r->created_by !== null && $r->created_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => 'The uploader of a record cannot review it (maker-checker).']);
            }
            $promoted = [];
            if ($decision === 'ACCEPTED' && $r->dataset === 'SIGNATORY_MANDATES') {
                $promoted = $this->promoteSignatory($r);
            }
            DB::table('tenant_onboarding_records')->where('id', $id)->update(['review_status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'review_note' => $note, 'updated_at' => now()] + $promoted);
            $this->audit->record('onboarding.private_record.'.strtolower($decision), 'tenant_onboarding_record', $id, ['dataset' => $r->dataset, 'key' => $r->record_key], $note);

            return DB::table('tenant_onboarding_records')->find($id);
        });
    }

    /** @return list<array<string, mixed>> one row per applicable dataset */
    public function readiness(string $organizationType, ?string $organizationId): array
    {
        $out = [];
        foreach (PrivateOnboardingTemplates::catalogue() as $d) {
            if (! in_array($organizationType, $d['organization_types'], true)) {
                continue;
            }
            $counts = DB::table('tenant_onboarding_records')->where(['dataset' => $d['dataset'], 'organization_type' => $organizationType])
                ->where(fn ($q) => $organizationId ? $q->where('organization_id', $organizationId) : $q->whereNull('organization_id'))
                ->selectRaw('review_status, count(*) n')->groupBy('review_status')->pluck('n', 'review_status');
            $accepted = (int) ($counts['ACCEPTED'] ?? 0);
            $received = (int) ($counts['RECEIVED'] ?? 0);
            $out[] = ['dataset' => $d['dataset'], 'owner' => $d['owner'], 'checklist_item' => $d['checklist_items'][$organizationType] ?? null,
                'received' => $received, 'accepted' => $accepted, 'rejected' => (int) ($counts['REJECTED'] ?? 0),
                'status' => $accepted > 0 ? 'SOURCE_RECEIVED' : ($received > 0 ? 'UNDER_REVIEW' : 'PENDING_PRIVATE_SOURCE'),
                // Staged evidence never makes the dataset production-usable: the owning domain must promote it.
                'production_usable' => false, 'promotion_table' => $d['promotion_table'], 'production_gate' => $d['production_gate'],
                'import' => ['target' => 'private_onboarding', 'params' => ['dataset' => $d['dataset'], 'organization_type' => $organizationType, 'organization_id' => $organizationId]]];
        }

        return $out;
    }

    private function promoteSignatory(object $r): array
    {
        if (! Schema::hasTable('signatory_authorities') || $r->organization_id === null) {
            return [];
        }
        $p = json_decode((string) $r->payload, true) ?: [];
        $list = fn ($v) => is_array($v) ? array_values($v) : array_values(array_filter(array_map('trim', explode('|', (string) $v))));
        $existing = DB::table('signatory_authorities')->where(['organization_type' => $r->organization_type, 'organization_id' => $r->organization_id, 'authority_id' => $r->record_key])->value('id');
        if ($existing) {
            return ['promoted_table' => 'signatory_authorities', 'promoted_id' => $existing];
        }
        $id = (string) Str::uuid();
        DB::table('signatory_authorities')->insert(['id' => $id, 'authority_id' => $r->record_key, 'tenant_id' => $r->tenant_id, 'organization_type' => $r->organization_type,
            'organization_id' => $r->organization_id, 'person_id' => (string) ($p['person_id'] ?? ''), 'role' => (string) ($p['role'] ?? ''),
            'document_types' => json_encode($list($p['document_types'] ?? '')), 'financial_limits' => isset($p['financial_limits']) && $p['financial_limits'] !== '' ? json_encode($p['financial_limits']) : null,
            'product_scope' => json_encode($list($p['product_scope'] ?? '')), 'branch_scope' => json_encode($list($p['branch_scope'] ?? '')),
            'signature_method' => ($p['signature_method'] ?? null) ?: null, 'certificate_id' => ($p['certificate_id'] ?? null) ?: null,
            'effective_from' => $r->effective_from, 'effective_until' => $r->effective_until, 'status' => 'PENDING_VERIFICATION',
            'source_mandate_document_id' => (string) $p['source_mandate_document_id'], 'onboarding_record_id' => $r->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['promoted_table' => 'signatory_authorities', 'promoted_id' => $id];
    }
}
