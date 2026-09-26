<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Http;

use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\Workspace\ProviderDocumentService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** D4 — provider documents (DOC-064..072, 198, 215, 216): provider portal and insurer back-office list / download. */
final class ProviderDocumentController
{
    public function __construct(private readonly ProviderDocumentService $docs, private readonly TenantContext $tenant) {}

    public function providerIndex(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->docs->listForProvider($r->user(), ProviderScope::of($r), ['type' => $r->query('type')])]);
    }

    public function providerDownload(Request $r, string $id): StreamedResponse
    {
        return $this->docs->downloadForProvider($r->user(), ProviderScope::of($r), $id);
    }

    public function insurerIndex(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->docs->listForInsurer($r->user(), (string) $this->tenant->id(), ['provider_id' => $r->query('provider_id'), 'type' => $r->query('type')])]);
    }

    public function insurerDownload(Request $r, string $id): StreamedResponse
    {
        return $this->docs->downloadForInsurer($r->user(), (string) $this->tenant->id(), $id);
    }
}
