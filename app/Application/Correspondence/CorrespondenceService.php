<?php

declare(strict_types=1);

namespace App\Application\Correspondence;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseJournal;
use App\Application\Cases\CaseProblem;
use App\Application\Cases\Models\WorkCase;
use App\Domain\Shared\Clock\Clock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-COR-001 correspondence registry (ICE gap 23): direction, channel, counterparty, reference, proof of dispatch.
 *
 * It does not send anything: notification_deliveries / communication_logs stay the transport and contact logs,
 * and a register row may point at them. A case-linked row is journalled on the case (CORRESPONDENCE_*), so the
 * case timeline is the single history. Proof of dispatch is write-once (a correction is a new row).
 */
final class CorrespondenceService
{
    public const CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP', 'LETTER', 'COURIER', 'PORTAL', 'PHONE', 'IN_PERSON'];

    public const COUNTERPARTY_TYPES = ['CUSTOMER', 'CARRIER', 'BROKER', 'REGULATOR', 'OMBUDSMAN', 'PROVIDER', 'LAWYER', 'OTHER'];

    public const PROOF_TYPES = ['PROVIDER_RECEIPT', 'REGISTERED_MAIL', 'COURIER_WAYBILL', 'SIGNED_ACKNOWLEDGEMENT', 'PORTAL_READ_RECEIPT', 'EMAIL_MESSAGE_ID'];

    public function __construct(private readonly CaseJournal $journal, private readonly AuditWriter $audit, private readonly Clock $clock) {}

    /** @param array<string, mixed> $d validated input */
    public function register(string $tenantId, array $d, ?User $actor): object
    {
        if (! empty($d['idempotency_key']) && ($existing = DB::table('correspondence_register')->where('tenant_id', $tenantId)->where('idempotency_key', $d['idempotency_key'])->first())) {
            return $existing;
        }
        $case = null;
        if (! empty($d['case_id'])) {
            $case = WorkCase::query()->where('tenant_id', $tenantId)->whereKey($d['case_id'])->first()
                ?? throw CaseProblem::make('CASE_NOT_FOUND', 422, 'The case is not visible in this tenant.');
        }
        foreach (['notification_delivery_id' => 'notification_deliveries', 'communication_log_id' => 'communication_logs'] as $col => $table) {
            if (! empty($d[$col]) && ! DB::table($table)->where('id', $d[$col])->where('tenant_id', $tenantId)->exists()) {
                throw CaseProblem::make('LINK_NOT_FOUND', 422, "{$table} row not found in this tenant.", ['field' => $col]);
            }
        }
        $inbound = $d['direction'] === 'INBOUND';
        $now = $this->clock->now();

        return DB::transaction(function () use ($tenantId, $d, $actor, $case, $inbound, $now) {
            $id = (string) Str::uuid();
            DB::table('correspondence_register')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'reference_number' => 'COR-'.$now->format('Ym').'-'.strtoupper(Str::random(8)),
                'case_id' => $case?->id, 'subject_type' => $d['subject_type'] ?? $case?->subject_type, 'subject_id' => $d['subject_id'] ?? $case?->subject_id,
                'direction' => $d['direction'], 'channel' => $d['channel'], 'counterparty_type' => $d['counterparty_type'],
                'counterparty_party_id' => $d['counterparty_party_id'] ?? null, 'counterparty_name' => $d['counterparty_name'],
                'counterparty_contact' => $d['counterparty_contact'] ?? null, 'external_reference' => $d['external_reference'] ?? null,
                'subject_line' => $d['subject_line'], 'summary' => $d['summary'] ?? null, 'document_id' => $d['document_id'] ?? null,
                'notification_delivery_id' => $d['notification_delivery_id'] ?? null, 'communication_log_id' => $d['communication_log_id'] ?? null,
                'status' => $inbound ? 'RECEIVED' : 'DRAFT', 'received_at' => $inbound ? ($d['received_at'] ?? $now) : null,
                'created_by' => $actor?->id, 'idempotency_key' => $d['idempotency_key'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = DB::table('correspondence_register')->where('id', $id)->first();
            $this->trace($row, $inbound ? 'CORRESPONDENCE_RECEIVED' : 'CORRESPONDENCE_DRAFTED', $actor);

            return $row;
        });
    }

    /**
     * Records dispatch (and optionally delivery) with its proof. Write-once: DISPATCHED can only advance to
     * DELIVERED/FAILED; proof fields never change once set.
     *
     * @param  array{proof_type: string, proof_reference?: ?string, proof_document_id?: ?string, dispatched_at?: ?string, delivered_at?: ?string}  $d
     */
    public function recordDispatch(string $tenantId, string $id, array $d, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $d, $actor) {
            $row = $this->lock($tenantId, $id);
            if ($row->direction !== 'OUTBOUND' || $row->status !== 'DRAFT') {
                throw CaseProblem::make('CORRESPONDENCE_NOT_DISPATCHABLE', 409, 'Only a DRAFT outbound correspondence can be dispatched; proof is write-once.', ['status' => $row->status]);
            }
            if (empty($d['proof_reference']) && empty($d['proof_document_id'])) {
                throw CaseProblem::make('PROOF_REQUIRED', 422, 'Proof of dispatch needs a proof_reference or a proof_document_id.');
            }
            $now = $this->clock->now();
            DB::table('correspondence_register')->where('id', $id)->update([
                'status' => empty($d['delivered_at']) ? 'DISPATCHED' : 'DELIVERED', 'dispatched_at' => $d['dispatched_at'] ?? $now, 'delivered_at' => $d['delivered_at'] ?? null,
                'proof_type' => $d['proof_type'], 'proof_reference' => $d['proof_reference'] ?? null, 'proof_document_id' => $d['proof_document_id'] ?? null,
                'proof_recorded_by' => $actor->id, 'updated_at' => $now,
            ]);
            $row = DB::table('correspondence_register')->where('id', $id)->first();
            $this->trace($row, 'CORRESPONDENCE_DISPATCHED', $actor);

            return $row;
        });
    }

    /** DISPATCHED → DELIVERED | FAILED (a failed response must be re-issued as a new row). */
    public function recordOutcome(string $tenantId, string $id, bool $delivered, ?string $reason, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $id, $delivered, $reason, $actor) {
            $row = $this->lock($tenantId, $id);
            if ($row->status !== 'DISPATCHED') {
                throw CaseProblem::make('CORRESPONDENCE_NOT_DISPATCHED', 409, 'Only a DISPATCHED correspondence can be marked delivered or failed.');
            }
            if (! $delivered && trim((string) $reason) === '') {
                throw CaseProblem::make('REASON_REQUIRED', 422, 'A failure reason is required.');
            }
            DB::table('correspondence_register')->where('id', $id)->update([
                'status' => $delivered ? 'DELIVERED' : 'FAILED', 'delivered_at' => $delivered ? $this->clock->now() : null,
                'failure_reason' => $delivered ? null : $reason, 'updated_at' => $this->clock->now(),
            ]);
            $row = DB::table('correspondence_register')->where('id', $id)->first();
            $this->trace($row, $delivered ? 'CORRESPONDENCE_DELIVERED' : 'CORRESPONDENCE_FAILED', $actor);

            return $row;
        });
    }

    private function lock(string $tenantId, string $id): object
    {
        $row = DB::table('correspondence_register')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
        if (! $row || ($row->case_id && ! WorkCase::query()->whereKey($row->case_id)->exists())) {
            throw CaseProblem::make('CORRESPONDENCE_NOT_FOUND', 404, 'Correspondence not found.'); // confidential case ⇒ invisible
        }

        return $row;
    }

    private function trace(object $row, string $type, ?User $actor): void
    {
        $meta = ['reference_number' => $row->reference_number, 'direction' => $row->direction, 'channel' => $row->channel, 'status' => $row->status];
        $this->audit->record('correspondence.'.strtolower(substr($type, 15)), 'correspondence', $row->id, $meta + ['case_id' => $row->case_id]);
        if ($row->case_id) {
            $case = WorkCase::withoutGlobalScopes()->whereKey($row->case_id)->lockForUpdate()->firstOrFail();
            $this->journal->event($case, $type, ['correspondence_id' => $row->id] + $meta, null, null, $actor?->id);
            $this->journal->publish('case.correspondence.'.strtolower(substr($type, 15)), 'case', $case->id, ['correspondence_id' => $row->id] + $meta);
        }
    }
}
