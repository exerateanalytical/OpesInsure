<?php

declare(strict_types=1);

namespace App\Application\Documents\Retention;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\DocumentGovernanceProblem;
use App\Application\Events\OutboxWriter;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-DOC-009 legal hold on ANY subject (ICE INV-5.5: legal hold overrides destruction).
 * A document is held when a hold is active on the document itself or on its policy, claim or party.
 * Legacy document-only holds (document_retention_holds) are still honoured.
 */
final class LegalHoldService
{
    public const SUBJECT_TYPES = ['DOCUMENT', 'POLICY', 'CLAIM', 'PARTY', 'CASE', 'QUOTE', 'PROPOSAL', 'KYC_SUBMISSION', 'RISK_ASSET', 'COMPLAINT'];

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox) {}

    /** @param array{subject_type: string, subject_id: string, reason_code: string, notes: string, case_id?: ?string, hold_until?: ?string} $d */
    public function place(string $tenantId, array $d, User $actor): object
    {
        if (! in_array($d['subject_type'], self::SUBJECT_TYPES, true)) {
            throw DocumentGovernanceProblem::make('UNKNOWN_SUBJECT_TYPE', 422, "Unknown legal hold subject type {$d['subject_type']}.");
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $d, $actor) {
            DB::table('legal_holds')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'subject_type' => $d['subject_type'], 'subject_id' => $d['subject_id'],
                'reason_code' => $d['reason_code'], 'notes' => $d['notes'], 'case_id' => $d['case_id'] ?? null, 'hold_until' => $d['hold_until'] ?? null,
                'placed_by' => $actor->id, 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('document.legal_hold.placed', 'legal_hold', $id, ['subject_type' => $d['subject_type'], 'subject_id' => $d['subject_id']], $d['reason_code']);
            $this->outbox->record('document.legal_hold.placed', 'legal_hold', $id, ['subject_type' => $d['subject_type'], 'subject_id' => $d['subject_id'], 'reason_code' => $d['reason_code']]);
        });

        return DB::table('legal_holds')->find($id);
    }

    public function release(string $tenantId, string $holdId, string $reason, User $actor): object
    {
        $hold = DB::table('legal_holds')->where('tenant_id', $tenantId)->where('id', $holdId)->first();
        if (! $hold) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Legal hold not found.');
        }
        if ($hold->released_at !== null) {
            throw DocumentGovernanceProblem::make('ALREADY_RELEASED', 409, 'Legal hold already released.');
        }
        if ($hold->placed_by === $actor->id) {
            throw DocumentGovernanceProblem::make('MAKER_CHECKER', 403, 'The user who placed a legal hold cannot release it.');
        }
        DB::transaction(function () use ($hold, $reason, $actor) {
            DB::table('legal_holds')->where('id', $hold->id)->update(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $reason, 'updated_at' => now()]);
            $this->audit->record('document.legal_hold.released', 'legal_hold', $hold->id, ['subject_type' => $hold->subject_type, 'subject_id' => $hold->subject_id], $reason);
            $this->outbox->record('document.legal_hold.released', 'legal_hold', $hold->id, ['subject_type' => $hold->subject_type, 'subject_id' => $hold->subject_id]);
        });

        return DB::table('legal_holds')->find($hold->id);
    }

    /** @return list<object> active holds covering the document (directly or through its subjects) */
    public function activeFor(Document $document): array
    {
        $subjects = array_filter(['DOCUMENT' => $document->id, 'POLICY' => $document->policy_id, 'CLAIM' => $document->claim_id, 'PARTY' => $document->party_id]);
        $holds = DB::table('legal_holds')->whereNull('released_at')
            ->where(fn ($q) => $q->whereNull('hold_until')->orWhere('hold_until', '>=', now()->toDateString()))
            ->where(function ($q) use ($subjects) {
                foreach ($subjects as $type => $id) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $type)->where('subject_id', $id));
                }
            })->get()->all();
        $legacy = DB::table('document_retention_holds')->where('document_id', $document->id)->whereNull('released_at')
            ->where(fn ($q) => $q->whereNull('hold_until')->orWhere('hold_until', '>=', now()->toDateString()))->get()
            ->map(fn ($h) => (object) ['id' => $h->id, 'subject_type' => 'DOCUMENT', 'subject_id' => $h->document_id, 'reason_code' => $h->reason_code, 'legacy' => true])->all();

        return array_merge($holds, $legacy);
    }

    public function isHeld(Document $document): bool
    {
        return $this->activeFor($document) !== [];
    }
}
