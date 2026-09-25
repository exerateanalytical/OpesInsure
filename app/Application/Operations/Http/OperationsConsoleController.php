<?php

declare(strict_types=1);

namespace App\Application\Operations\Http;

use App\Application\Compliance\Governance\GovernanceRegisterService;
use App\Application\Operations\CorrelationViewService;
use App\Application\Operations\IntegrationMonitorService;
use App\Application\Operations\OperationalExceptionQueue;
use App\Application\Operations\QueueConsoleService;
use App\Application\Operations\SystemHealthService;
use App\Domain\Tenancy\TenantContext;
use App\Models\RecoveryExercise;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Agent B6 — REQ-OPS-001/002/005 operations console API. The incidents register is the existing ICT incident register
 * (GovernanceRegisterService 'ict-incidents'), exposed here for operators — not a second incidents table.
 */
final class OperationsConsoleController
{
    public const PLATFORM_PERMISSION = 'operations.platform.view';

    public function __construct(private TenantContext $tenant) {}

    public function health(SystemHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->summary()]);
    }

    public function integrations(Request $r, IntegrationMonitorService $monitor): JsonResponse
    {
        $data = $monitor->summary($this->tenant->id());
        if ($this->platform($r)) {
            $data['failed_webhooks'] = $monitor->failedWebhooks($r->query('provider'));
            $data['dead_lettered_deliveries'] = $monitor->deadLetteredDeliveries();
        } else {
            unset($data['webhooks'], $data['integrations']);
        }

        return response()->json(['data' => $data]);
    }

    public function failedJobs(Request $r, QueueConsoleService $queues): JsonResponse
    {
        $f = $r->validate(['queue' => 'nullable|string|max:255', 'per_page' => 'nullable|integer|min:1|max:100']);

        return response()->json($queues->failed($f['queue'] ?? null, (int) ($f['per_page'] ?? 25)));
    }

    public function retryJob(string $uuid, QueueConsoleService $queues): JsonResponse
    {
        return response()->json(['data' => $queues->retry($uuid)]);
    }

    public function forgetJob(Request $r, string $uuid, QueueConsoleService $queues): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);

        return response()->json(['data' => $queues->forget($uuid, $d['reason'])]);
    }

    public function correlation(string $correlationId, CorrelationViewService $view): JsonResponse
    {
        return response()->json(['data' => $view->show($this->tenant->id(), $correlationId)]);
    }

    public function exceptions(Request $r, OperationalExceptionQueue $queue): JsonResponse
    {
        $f = $r->validate(['sources' => 'nullable|array', 'sources.*' => ['string', Rule::in(OperationalExceptionQueue::sources())], 'as_of' => 'nullable|date']);

        return response()->json(['data' => $queue->summary($this->tenant->id(), $this->platform($r), isset($f['as_of']) ? CarbonImmutable::parse($f['as_of']) : null, $f['sources'] ?? null)]);
    }

    public function incidents(Request $r, GovernanceRegisterService $registers): JsonResponse
    {
        $f = $r->validate(['status' => 'nullable|in:OPEN,CONTAINED,RESOLVED,CLOSED', 'per_page' => 'nullable|integer|min:1|max:100']);
        $page = DB::table($registers->table('ict-incidents'))->where('tenant_id', $this->tenant->id())
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('detected_at')->paginate((int) ($f['per_page'] ?? 50));

        return response()->json($page);
    }

    public function storeIncident(Request $r, GovernanceRegisterService $registers): JsonResponse
    {
        return response()->json(['data' => $registers->create('ict-incidents', $this->tenant->id(), $r->all(), $r->user())], 201);
    }

    public function updateIncident(Request $r, string $id, GovernanceRegisterService $registers): JsonResponse
    {
        return response()->json(['data' => $registers->update('ict-incidents', $this->tenant->id(), $id, $r->all(), $r->user())]);
    }

    public function restoreVerifications(): JsonResponse
    {
        return response()->json([
            'data' => RecoveryExercise::query()->where('exercise_type', 'BACKUP_RESTORE')->latest('created_at')->limit(50)->get(),
            'targets' => ['rpo_minutes' => config('operations.dr.target_rpo_minutes'), 'rto_minutes' => config('operations.dr.target_rto_minutes')],
        ]);
    }

    private function platform(Request $r): bool
    {
        return (bool) $r->user()?->hasPermission(self::PLATFORM_PERMISSION);
    }
}
