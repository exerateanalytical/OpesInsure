<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening\Http;

use App\Application\Compliance\Aml\Screening\ComplianceGate;
use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListSource;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListVersion;
use App\Application\Compliance\Aml\Screening\ScreeningListService;
use App\Application\Compliance\Aml\Screening\ScreeningService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Agent E8 — REQ-AML-001 / REQ-KYC-004 screening API: list sources, versioned imports, party screening, hit dispositions. */
final class ScreeningController
{
    public function __construct(private TenantContext $tenant, private ScreeningListService $lists, private ScreeningService $screening, private ComplianceGate $gate) {}

    public function sources(): JsonResponse
    {
        $sources = ScreeningListSource::where('tenant_id', $this->tenant->id())->orderBy('code')->get();
        $active = ScreeningListVersion::where('tenant_id', $this->tenant->id())->where('status', 'ACTIVE')->get()->keyBy('source_id');

        return response()->json(['data' => $sources->map(fn ($s) => $s->toArray() + ['active_version' => $active[$s->id]?->only(['id', 'version', 'entry_count', 'activated_at'])])->all()]);
    }

    public function storeSource(Request $r): JsonResponse
    {
        $d = $r->validate(['code' => 'required|string|max:64|regex:/^[A-Za-z0-9_.-]+$/', 'name' => 'required|string|max:160',
            'list_type' => 'required|string|in:PEP,SANCTIONS,WATCHLIST', 'publisher' => 'nullable|string|max:160']);

        return response()->json(['data' => $this->lists->createSource($this->tenant->id(), $d, $r->user())], 201);
    }

    public function import(Request $r, string $source): JsonResponse
    {
        $d = $r->validate(['format' => 'required|string|in:CSV,JSON,csv,json', 'content' => 'required_if:format,CSV,csv|nullable|string',
            'entries' => 'required_if:format,JSON,json|nullable|array', 'source_reference' => 'nullable|string|max:255']);
        $src = ScreeningListSource::where('tenant_id', $this->tenant->id())->whereKey($source)->firstOrFail();
        $content = strtoupper($d['format']) === 'CSV' ? (string) $d['content'] : ($d['entries'] ?? []);

        return response()->json(['data' => $this->lists->import($src, $d['format'], $content, $d['source_reference'] ?? null, $r->user())], 201);
    }

    public function showVersion(string $version): JsonResponse
    {
        $v = $this->version($version);

        return response()->json(['data' => $v->toArray() + ['entries' => DB::table('screening_list_entries')->where('version_id', $v->id)
            ->orderBy('entry_ref')->limit(500)->get(['id', 'entry_ref', 'entry_type', 'name', 'aliases', 'date_of_birth', 'country'])]]);
    }

    public function decideVersion(Request $r, string $version): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|string|in:APPROVE,REJECT', 'note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->lists->decide($this->version($version), $d['decision'] === 'APPROVE', $d['note'] ?? null, $r->user())]);
    }

    public function screenParty(Request $r, string $party): JsonResponse
    {
        $p = $this->party($party);
        $res = $this->screening->screenParty($this->tenant->id(), $p, 'MANUAL_RESCREEN', $r->user());

        return response()->json(['data' => ['check' => $res['check'], 'hits' => $res['hits'], 'screened' => $res['check'] !== null]]);
    }

    public function hits(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'nullable|string|in:OPEN,PROPOSED,DISPOSED', 'party_id' => 'nullable|uuid', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = ScreeningHit::where('tenant_id', $this->tenant->id())->orderByDesc('created_at');
        foreach (['status', 'party_id'] as $f) {
            if (! empty($d[$f])) {
                $q->where($f, $d[$f]);
            }
        }

        return response()->json($q->paginate($d['per_page'] ?? 25));
    }

    public function propose(Request $r, string $hit): JsonResponse
    {
        $d = $r->validate(['disposition' => 'required|string|in:FALSE_POSITIVE,TRUE_MATCH,ESCALATED', 'rationale' => 'required|string|max:4000']);

        return response()->json(['data' => $this->screening->propose($this->hit($hit), $d['disposition'], $d['rationale'], $r->user())]);
    }

    public function decide(Request $r, string $hit): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|string|in:APPROVE,REJECT', 'note' => 'nullable|string|max:4000']);

        return response()->json(['data' => $this->screening->decide($this->hit($hit), $d['decision'] === 'APPROVE', $d['note'] ?? null, $r->user())]);
    }

    public function partyStatus(string $party): JsonResponse
    {
        $p = $this->party($party);
        $blocking = $this->screening->blockingHits($this->tenant->id(), [$p->id]);

        return response()->json(['data' => ['party_id' => $p->id, 'gate_mode' => $this->gate->mode($this->tenant->id()), 'blocked' => $blocking->isNotEmpty(),
            'blocking_hits' => $blocking->values(), 'last_check' => DB::table('screening_checks')->where('tenant_id', $this->tenant->id())->where('party_id', $p->id)
                ->where('provider', ScreeningService::PROVIDER)->orderByDesc('checked_at')->first()]]);
    }

    private function version(string $id): ScreeningListVersion
    {
        return ScreeningListVersion::where('tenant_id', $this->tenant->id())->whereKey($id)->firstOrFail();
    }

    private function hit(string $id): ScreeningHit
    {
        return ScreeningHit::where('tenant_id', $this->tenant->id())->whereKey($id)->firstOrFail();
    }

    /** Parties are global; the party must be a customer of the tenant (tenant_customers) to be screened here. */
    private function party(string $id): Party
    {
        abort_unless(DB::table('tenant_customers')->where('tenant_id', $this->tenant->id())->where('party_id', $id)->exists(), 404);

        return Party::findOrFail($id);
    }
}
