<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy\Http;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Application\OperationsTaxonomy\OperationsLabels;
use App\Application\OperationsTaxonomy\OperationsTaxonomyService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Gap Closure Pack file 10 — taxonomy read API, manual case escalation, queue typing, platform notification template approval. */
final class OperationsTaxonomyController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(): JsonResponse
    {
        $lists = [];
        foreach (array_keys(OperationsCatalogue::LISTS) as $key) {
            $lists[$key] = array_map(fn ($c) => ['code' => $c, 'label_en' => OperationsLabels::en($c), 'label_fr' => OperationsLabels::fr($c)], OperationsCatalogue::list($key));
        }
        $gates = [];
        foreach (array_keys(OperationsCatalogue::GATES) as $g) {
            $gates[$g] = OperationsCatalogue::gateStatus($g);
        }

        return response()->json(['data' => [
            'lists' => $lists, 'gates' => $gates, 'channels' => (array) (OperationsCatalogue::pack()['channels'] ?? []),
            'case_families' => array_map(fn ($f) => ['code' => $f, 'canonical' => OperationsCatalogue::canonicalFamily($f)], (array) data_get(OperationsCatalogue::pack(), 'case_taxonomy.families', [])),
            'fields' => ['retention' => OperationsCatalogue::fields('retention'), 'sla_profiles' => OperationsCatalogue::fields('sla_profiles'),
                'document_numbering_profiles' => OperationsCatalogue::fields('document_numbering_profiles'), 'signatory_authority_registry' => OperationsCatalogue::fields('signatory_authority_registry')],
            'source' => OperationsCatalogue::SOURCE,
        ]]);
    }

    public function escalate(Request $r, string $case, CaseService $cases, AuditWriter $audit): JsonResponse
    {
        $d = $r->validate(['escalation_reason' => 'required|string|max:32', 'queue_id' => 'nullable|uuid', 'owner_user_id' => 'nullable|uuid', 'note' => 'nullable|string|max:1000']);
        OperationsCatalogue::assert('escalation_reasons', $d['escalation_reason'], 'escalation_reason');
        if ($d['escalation_reason'] === 'OTHER' && trim((string) ($d['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => 'A note is required for escalation reason OTHER.']);
        }
        $c = WorkCase::where('tenant_id', $this->tenant->id())->findOrFail($case);
        $c = DB::transaction(function () use ($c, $d, $cases, $r, $audit) {
            if (! empty($d['queue_id']) || ! empty($d['owner_user_id'])) {
                $c = $cases->assign($c, $d['owner_user_id'] ?? null, $d['queue_id'] ?? null, $r->user(), 'ESCALATION:'.$d['escalation_reason']);
            }
            WorkCase::withoutGlobalScopes()->whereKey($c->id)->update(['escalation_reason' => $d['escalation_reason'], 'escalated_at' => now(),
                'priority' => in_array($c->priority, ['LOW', 'NORMAL'], true) ? 'HIGH' : $c->priority]);
            $fresh = WorkCase::withoutGlobalScopes()->find($c->id);
            app(\App\Application\Cases\CaseJournal::class)->event($fresh, 'ESCALATED', ['escalation_reason' => $d['escalation_reason'], 'note' => $d['note'] ?? null, 'queue_id' => $d['queue_id'] ?? null, 'manual' => true], null, null, $r->user()->id);
            $audit->record('case.escalated', 'case', $c->id, ['escalation_reason' => $d['escalation_reason']], $d['note'] ?? null);

            return WorkCase::withoutGlobalScopes()->find($c->id);
        });

        return response()->json(['data' => $c]);
    }

    public function queueType(Request $r, string $queue, OperationsTaxonomyService $taxonomy): JsonResponse
    {
        $d = $r->validate(['queue_type' => 'required|string|max:32']);

        return response()->json(['data' => $taxonomy->setQueueType($this->tenant->id(), $queue, $d['queue_type'])]);
    }

    public function notificationTemplates(Request $r): JsonResponse
    {
        $d = $r->validate(['event_code' => 'nullable|string|max:48', 'status' => 'nullable|string|max:24']);

        return response()->json(['data' => DB::table('notification_templates')->whereNotNull('event_code')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id()))
            ->when($d['event_code'] ?? null, fn ($q, $v) => $q->where('event_code', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('event_code')->orderBy('channel')->orderBy('locale')->get()]);
    }

    /** Platform (tenant_id NULL) event templates are seeded DRAFT: an administrator approves each before it can be queued. */
    public function approveTemplate(Request $r, string $template, OperationsTaxonomyService $taxonomy): JsonResponse
    {
        return response()->json(['data' => $taxonomy->approvePlatformTemplate($template, $r->user())]);
    }
}
