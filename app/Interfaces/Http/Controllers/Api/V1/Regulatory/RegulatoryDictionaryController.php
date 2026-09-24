<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Regulatory;

use App\Application\Regulatory\RegulatoryTerminologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public CIMA reference data (terminology, Art. 328 branches, Art. 717 micro branches, Art. 411/557 reporting). */
final class RegulatoryDictionaryController
{
    public function __construct(private readonly RegulatoryTerminologyService $terms) {}

    public function terms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'regime' => 'nullable|string|in:CIMA,cima', 'locale' => 'nullable|string|max:8',
            'code' => 'nullable|string|max:96', 'category' => 'nullable|string|max:32',
        ]);
        $locale = $this->terms->locale($data['locale'] ?? $request->getPreferredLanguage(RegulatoryTerminologyService::LOCALES));

        return $this->cached(['regime' => 'CIMA', 'locale' => $locale, 'data' => $this->terms->terms('CIMA', $locale, $data['code'] ?? null, $data['category'] ?? null)]);
    }

    public function branches(Request $request): JsonResponse
    {
        $locale = $this->terms->locale($request->query('locale'));

        return $this->cached(['regime' => 'CIMA', 'locale' => $locale, 'legal_reference' => 'Article 328', 'data' => $this->terms->branches($locale)]);
    }

    public function microBranches(Request $request): JsonResponse
    {
        $locale = $this->terms->locale($request->query('locale'));

        return $this->cached(['regime' => 'CIMA', 'locale' => $locale, 'legal_reference' => 'Article 717', 'data' => $this->terms->microBranches($locale)]);
    }

    public function reportingCategories(Request $request): JsonResponse
    {
        $locale = $this->terms->locale($request->query('locale'));

        return $this->cached(['regime' => 'CIMA', 'locale' => $locale, 'data' => $this->terms->reportingCategories($locale)]);
    }

    private function cached(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'public, max-age=3600');
    }
}
