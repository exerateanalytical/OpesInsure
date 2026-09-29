<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\Agents\AgentClientIntakeService;
use App\Application\Documents\MobileDocumentService;
use App\Application\Kyc\KycService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Party;
use App\Models\TenantCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Q3 launch (AGT-014 Customer KYC, AGT-015 KYC Document Capture, AGT-030/033 proposal documents):
 * agent-assisted capture for a client in the agent's own book. Thin adapter: the client is resolved
 * through AgentClientIntakeService::show (ACTIVE attribution to the agent's partner, 403 otherwise),
 * files go through MobileDocumentService::upload as the client's own document, KYC through KycService.
 */
final class AgentClientAssistController
{
    public function __construct(private AgentClientIntakeService $intake, private KycService $kyc) {}

    /** GET mobile/partner/agent/clients/{customer}/kyc — latest submission (or none) with its requirements. */
    public function kyc(string $customer, Request $request): JsonResponse
    {
        $c = $this->client($customer, $request);
        $s = $this->kyc->latest($this->tenant(), $c->party_id);

        return response()->json(['data' => ['customer_id' => $c->id, 'submission' => $s ? $this->kyc->present($s) : null]]);
    }

    /** POST mobile/partner/agent/clients/{customer}/documents — store a file as the client's own document. */
    public function upload(string $customer, Request $request, MobileDocumentService $documents): JsonResponse
    {
        $c = $this->client($customer, $request);
        $data = $request->validate(['category' => 'required|string|max:48', 'mime_type' => 'required|in:application/pdf,image/jpeg,image/png', 'file_base64' => 'required|string']);

        return response()->json(['data' => $documents->upload($data, $request->user(), $this->tenant(), Party::findOrFail($c->party_id))], 201);
    }

    /** POST mobile/partner/agent/clients/{customer}/kyc/documents {document_id, purpose} — attach to the client's draft submission. */
    public function attach(string $customer, Request $request): JsonResponse
    {
        $c = $this->client($customer, $request);
        $d = $request->validate(['document_id' => 'required|uuid', 'purpose' => 'required|string|max:64']);
        $doc = \App\Models\Document::where('tenant_id', $this->tenant())->where('party_id', $c->party_id)->findOrFail($d['document_id']);
        $s = $this->kyc->attachDocument($this->kyc->draftFor(Party::findOrFail($c->party_id), $this->tenant()), $doc, $d['purpose'], $request->user());

        return response()->json(['data' => $this->kyc->present($s->fresh())], 201);
    }

    /** POST mobile/partner/agent/clients/{customer}/kyc/submit — DRAFT / MORE_INFO_REQUIRED → review. */
    public function submit(string $customer, Request $request): JsonResponse
    {
        $c = $this->client($customer, $request);
        $notes = $request->validate(['notes' => 'nullable|string|max:2000'])['notes'] ?? null;
        $s = $this->kyc->latest($this->tenant(), $c->party_id) ?? abort(404);

        return response()->json(['data' => $this->kyc->present($this->kyc->submit($s, $notes, $request->user())->fresh())]);
    }

    private function client(string $id, Request $request): TenantCustomer
    {
        return $this->intake->show($id, $request->user(), $this->tenant());
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
