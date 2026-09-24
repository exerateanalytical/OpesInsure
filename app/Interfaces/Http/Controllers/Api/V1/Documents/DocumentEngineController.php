<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Documents;

use App\Application\Documents\Engine\CarrierDocumentService;
use App\Application\Documents\Engine\DocumentStatusService;
use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\Engine\PolicyDocumentsQuery;
use App\Application\Documents\Engine\PolicyPackArchiver;
use App\Application\Documents\Engine\ProductDocumentGate;
use App\Application\Policies\MobileWalletService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Document;
use App\Models\DocumentStatusChange;
use App\Models\DocumentTemplate;
use App\Models\InsuranceProduct;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Document engine HTTP surface (routes/document_engine.php). */
final class DocumentEngineController
{
    // ---- customer (own policy only) -------------------------------------

    public function policyDocuments(string $policy, Request $request, MobileWalletService $wallet, PolicyDocumentsQuery $query): JsonResponse
    {
        $data = $request->validate(['group' => 'nullable|in:POLICY_PACK,CERTIFICATES,SERVICING,CLAIMS,FINANCIAL', 'stage' => 'nullable|in:PRE_CONTRACT,UNDERWRITING,POLICY,SERVICING,RENEWAL,CLAIM,SETTLEMENT,FINANCE,COMPLIANCE']);
        $owned = $wallet->policy($policy, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $query->forCustomer($owned, $data['group'] ?? null, $data['stage'] ?? null)]);
    }

    public function packUrl(string $policy, Request $request, MobileWalletService $wallet): JsonResponse
    {
        $owned = $wallet->policy($policy, $request->user(), app(TenantContext::class)->id());
        $manifest = $request->query('manifest');

        return response()->json(['data' => ['url' => PolicyDocumentsQuery::packUrl($owned, is_string($manifest) ? $manifest : null), 'format' => 'zip', 'expires_in_minutes' => (int) config('lifecycle.download_ttl_minutes', 30)]]);
    }

    /** Signed link: the unexpired signature is the authorization (same model as policy-document downloads). */
    public function packDownload(string $policy, Request $request, PolicyPackArchiver $archiver): BinaryFileResponse
    {
        $record = Policy::find($policy);
        abort_unless($record, 404);
        $path = $archiver->build($record, is_string($request->query('manifest')) ? $request->query('manifest') : null);

        return response()->download($path, 'policy-pack-'.preg_replace('/[^A-Za-z0-9-]+/', '', (string) $record->policy_number).'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    // ---- staff ----------------------------------------------------------

    public function uploadCarrierDocument(string $policy, Request $request, CarrierDocumentService $service): JsonResponse
    {
        $record = Policy::where('tenant_id', app(TenantContext::class)->id())->findOrFail($policy);
        $data = $request->validate([
            'file' => 'required|file|max:20480', 'document_type_code' => 'required|string|max:80', 'issue_date' => 'required|date',
            'carrier_document_number' => 'nullable|string|max:100', 'carrier_version' => 'nullable|string|max:40', 'language' => 'nullable|in:FR,EN,BILINGUAL',
            'subject_key' => 'nullable|string|max:120', 'subject_label' => 'nullable|string|max:160', 'valid_until' => 'nullable|date', 'issuer' => 'nullable|in:INSURER,BROKER',
        ]);
        $file = $request->file('file');
        $doc = $service->upload($record, (string) file_get_contents($file->getRealPath()), (string) $file->getMimeType(), $data, $request->user());

        return response()->json(['data' => ['id' => $doc->id, 'status' => $doc->status, 'verification_code' => $doc->verification_code, 'sha256' => $doc->sha256]], 201);
    }

    public function requestStatusChange(string $document, Request $request, DocumentStatusService $service): JsonResponse
    {
        $doc = Document::where('tenant_id', app(TenantContext::class)->id())->findOrFail($document);
        $data = $request->validate(['action' => 'required|in:REVOKE,REPLACE,CANCEL', 'reason' => 'required|string|min:5|max:1000', 'replacement_document_id' => 'nullable|uuid']);
        $change = $service->request($doc, $data['action'], $data['reason'], $request->user(), $data['replacement_document_id'] ?? null);

        return response()->json(['data' => $change], 201);
    }

    public function decideStatusChange(string $change, string $decision, Request $request, DocumentStatusService $service): JsonResponse
    {
        $record = DocumentStatusChange::whereHas('document', fn ($q) => $q->where('tenant_id', app(TenantContext::class)->id()))->findOrFail($change);
        $note = (string) $request->input('note', '');
        $result = $decision === 'approve' ? $service->approve($record, $request->user(), $note ?: null) : $service->reject($record, $request->user(), $note ?: 'Rejected');

        return response()->json(['data' => $result]);
    }

    public function productGate(string $product, ProductDocumentGate $gate): JsonResponse
    {
        return response()->json(['data' => $gate->evaluate(InsuranceProduct::findOrFail($product))]);
    }

    public function createTemplate(Request $request, DocumentTemplateService $templates): JsonResponse
    {
        $data = $request->validate([
            'document_type_code' => 'required|string|max:80', 'ownership' => 'required|in:PLATFORM,INSURER,BROKER,REGULATORY', 'language' => 'required|in:FR,EN,BILINGUAL',
            'carrier_id' => 'nullable|uuid|exists:carriers,id', 'broker_tenant_id' => 'nullable|uuid|exists:tenants,id', 'product_id' => 'nullable|uuid|exists:insurance_products,id',
            'insurance_class' => 'nullable|string|max:40', 'title_en' => 'nullable|string|max:200', 'title_fr' => 'nullable|string|max:200',
            'content' => 'required|array', 'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        return response()->json(['data' => $templates->createDraft($data, $request->user())], 201);
    }

    public function templateTransition(string $template, string $action, Request $request, DocumentTemplateService $templates): JsonResponse
    {
        $t = DocumentTemplate::findOrFail($template);
        $user = $request->user();
        $result = match ($action) {
            'submit' => $templates->submit($t, $user),
            'approve' => $templates->approve($t, $user),
            'publish' => $templates->publish($t, $user, $request->input('effective_from')),
            'retire' => $templates->retire($t, (string) $request->input('reason', 'Retired'), $user),
            default => abort(404),
        };

        return response()->json(['data' => $result]);
    }
}
