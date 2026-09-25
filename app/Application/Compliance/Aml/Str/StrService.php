<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Str;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Models\RiskAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * REQ-AML-003 — suspicious transaction reports on the case engine (case type STR, confidentiality STR_RESTRICTED).
 * Only a holder of cases.str.view (compliance officer / MLRO) can draft, read or submit an STR; for anyone else an
 * STR does not exist (404, never 403). Four eyes: the submitter differs from the drafter (DB CHECK too).
 * Tipping-off (Reg. 003-25): no outbox event and no notification are raised; audit rows are subject_type aml_str
 * and hidden from audit-log readers without cases.str.view (TippingOffGuard).
 * The regulator transmission channel and deadline are not modelled (owner question).
 */
final class StrService
{
    public const PERMISSION = 'cases.str.view';

    public function __construct(private readonly CaseService $cases, private readonly AuditWriter $audit) {}

    /** @param array{party_id?: ?string, grounds: string, related_alert_ids?: list<string>} $d @return array<string, mixed> */
    public function draft(string $tenantId, array $d, User $actor): array
    {
        $this->authorize($actor);
        $alerts = array_values(array_unique((array) ($d['related_alert_ids'] ?? [])));
        if ($alerts !== [] && RiskAlert::where('tenant_id', $tenantId)->whereIn('id', $alerts)->count() !== count($alerts)) {
            throw ValidationException::withMessages(['related_alert_ids' => 'Unknown risk alert.']);
        }

        return DB::transaction(function () use ($tenantId, $d, $actor, $alerts) {
            $id = (string) Str::uuid();
            $case = $this->cases->open($tenantId, 'STR', [
                'title' => 'Suspicious transaction report', 'priority' => 'HIGH', 'confidentiality' => 'STR_RESTRICTED',
                'subject_type' => ! empty($d['party_id']) ? 'party' : null, 'subject_id' => $d['party_id'] ?? null,
                'source_type' => TippingOffGuard::AUDIT_SUBJECT, 'source_id' => $id, 'idempotency_key' => 'aml-str:'.$id,
            ], $actor);
            DB::table('aml_str_reports')->insert(['id' => $id, 'tenant_id' => $tenantId, 'case_id' => $case->id, 'party_id' => $d['party_id'] ?? null,
                'status' => 'DRAFT', 'grounds' => $d['grounds'], 'related_alert_ids' => json_encode($alerts), 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('aml.str.drafted', TippingOffGuard::AUDIT_SUBJECT, $id, ['case_id' => $case->id, 'related_alerts' => count($alerts)]);

            return $this->show($tenantId, $id, $actor);
        });
    }

    /** @return array<string, mixed> */
    public function submit(string $tenantId, string $id, string $regulatorReference, User $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($tenantId, $id, $regulatorReference, $actor) {
            $row = DB::table('aml_str_reports')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException;
            if ($row->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Only a draft STR can be submitted.']);
            }
            if ($row->created_by === $actor->id) {
                throw ValidationException::withMessages(['status' => 'The STR must be submitted by a second compliance officer (four eyes).']);
            }
            DB::table('aml_str_reports')->where('id', $id)->update(['status' => 'SUBMITTED', 'regulator_reference' => $regulatorReference,
                'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_at' => now()]);
            $this->audit->record('aml.str.submitted', TippingOffGuard::AUDIT_SUBJECT, $id, ['case_id' => $row->case_id]);

            return $this->show($tenantId, $id, $actor);
        });
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId, string $id, User $actor): array
    {
        $this->authorize($actor);
        $row = DB::table('aml_str_reports')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? throw new NotFoundHttpException;

        return $this->present($row);
    }

    /** @return list<array<string, mixed>> */
    public function index(string $tenantId, User $actor): array
    {
        $this->authorize($actor);

        return DB::table('aml_str_reports')->where('tenant_id', $tenantId)->orderByDesc('created_at')->limit(200)->get()->map(fn ($r) => $this->present($r))->all();
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasPermission(self::PERMISSION)) {
            throw new NotFoundHttpException;
        }
    }

    /** @return array<string, mixed> */
    private function present(object $r): array
    {
        return ['id' => $r->id, 'case_id' => $r->case_id, 'party_id' => $r->party_id, 'status' => $r->status, 'grounds' => $r->grounds,
            'related_alert_ids' => json_decode((string) $r->related_alert_ids, true), 'regulator_reference' => $r->regulator_reference,
            'created_by' => $r->created_by, 'submitted_by' => $r->submitted_by, 'submitted_at' => $r->submitted_at, 'created_at' => (string) $r->created_at];
    }
}
