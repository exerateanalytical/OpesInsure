<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MasterData;

use App\Application\MasterData\MasterDataCache;
use App\Application\MasterData\MasterDataCatalogue;
use App\Application\MasterData\MasterDataReviewService;
use App\Application\MasterData\MasterDataSearch;
use App\Application\MasterData\VehicleMasterSource;
use App\Application\Vehicles\VehicleSuggestionIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Institutional master data API (catalogue v1.0).
 *   GET  /api/v1/master-data/versions                     {data:{domains:{code:{version,updated_at}}}}
 *   GET  /api/v1/master-data                              domain index
 *   GET  /api/v1/master-data/{domain}[?list=]             all lists with EN/FR labels and parent codes (hierarchies)
 *   GET  /api/v1/master-data/{domain}/search?list=&q=&parent=&limit=
 *   GET  /api/v1/master-data/{domain}/{id}                value by uuid, or list by code (?q= searches it)
 *   POST /api/v1/master-data/suggestions                  Other / Not listed -> review queue (auth)
 * Aliases: GET /api/v1/public/master-data/{domain}[/{list}], POST /api/v1/mobile/master-data/review.
 * `label` is {en, fr}; `display` is the label in ?locale= (or Accept-Language).
 */
final class MasterDataController
{
    public function __construct(private readonly MasterDataCatalogue $catalogue, private readonly MasterDataSearch $search) {}

    public function versions(): JsonResponse
    {
        return response()->json(['data' => ['domains' => MasterDataCache::versions(), 'generated_at' => now()->toIso8601String()]])
            ->header('Cache-Control', 'no-cache');
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->domains()]);
    }

    public function domain(string $domain, Request $request): JsonResponse
    {
        $data = $this->catalogue->domain($domain);
        abort_if(! $data, 404, 'Unknown master data domain.');
        $locale = $this->locale($request);
        if ($list = $request->query('list')) {
            $data['lists'] = array_values(array_filter($data['lists'], fn ($l) => $l['code'] === $list));
        }
        $data['lists'] = array_map(fn ($l) => ['display' => $l['label'][$locale]] + ['values' => $this->localize($l['values'], $locale)] + $l, $data['lists']);
        $etag = 'md-'.$domain.'-'.$data['catalog_version'].'-'.$locale;
        if (trim((string) $request->header('If-None-Match'), '"') === $etag) {
            return response()->json(null, 304)->setEtag($etag);
        }

        return response()->json(['data' => $data])->setEtag($etag)->header('Cache-Control', 'public, max-age=300');
    }

    public function search(string $domain, Request $request): JsonResponse
    {
        $q = $request->validate(['list' => 'required|string|max:64', 'q' => 'nullable|string|max:100', 'parent' => 'nullable|string|max:128', 'limit' => 'nullable|integer|min:1|max:500']);

        return $this->searchList($domain, $q['list'], $request);
    }

    public function show(string $domain, string $id, Request $request): JsonResponse
    {
        if (Str::isUuid($id)) {
            $list = collect($this->catalogue->domain($domain)['lists'] ?? [])->first(fn ($l) => collect($l['values'])->contains('id', $id));
            $value = $list ? collect($list['values'])->firstWhere('id', $id) : null;
            abort_if(! $value, 404, 'Unknown master data value.');
            unset($value['search']);

            return response()->json(['data' => $value + ['list' => $list['code'], 'domain' => $domain, 'display' => $value['label'][$this->locale($request)]]]);
        }

        return $this->searchList($domain, $id, $request);
    }

    public function suggest(Request $request, MasterDataReviewService $reviews): JsonResponse
    {
        // REQ-DUP-013: domain "vehicle" is owned by the vehicle master and has its own review queue.
        if ($request->input('domain') === VehicleSuggestionIntake::DOMAIN) {
            $d = $request->validate(['list' => 'required|string|max:64', 'text' => 'required|string|min:1|max:120', 'parent' => 'nullable|string|max:128', 'attributes' => 'nullable|array']
                + VehicleSuggestionIntake::attributeRules('attributes.'));
            $result = app(VehicleSuggestionIntake::class)->suggest($d, $request->user());

            return response()->json(['data' => $result], $result['status'] === 'MATCHED' ? 200 : 201);
        }
        $d = $request->validate([
            'domain' => 'required|string|max:64', 'list' => 'required|string|max:64', 'text' => 'required|string|max:200',
            'parent' => 'nullable|string|max:128', 'line_code' => 'nullable|string|max:32', 'field_key' => 'nullable|string|max:64',
            'screen' => 'nullable|string|max:128', 'suggested_category' => 'nullable|string|max:255',
        ]);
        $result = $reviews->submit($d['domain'], $d['list'], $d['text'], [
            'parent_code' => $d['parent'] ?? null, 'locale' => $this->locale($request), 'user_id' => $request->user()?->getAuthIdentifier(),
            'tenant_id' => $this->tenantId($request), 'screen' => $d['screen'] ?? null, 'line_code' => $d['line_code'] ?? null,
            'field_key' => $d['field_key'] ?? null, 'suggested_category' => $d['suggested_category'] ?? null,
        ]);

        return response()->json(['data' => $result], $result['status'] === 'MATCHED' ? 200 : 201);
    }

    private function searchList(string $domain, string $list, Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $limit = (int) $request->query('limit', 200);
        if ($domain === VehicleMasterSource::DOMAIN) {
            $items = app(VehicleMasterSource::class)->search($list, $request->query('q'), $request->query('parent'), $limit);
            abort_if($items === null, 404, 'Unknown master data list.');

            return response()->json(['data' => ['domain' => $domain, 'list' => $list, 'allow_other' => true, 'values' => $this->localize($items, $locale)]]);
        }
        $meta = $this->catalogue->list($domain, $list);
        abort_if(! $meta, 404, 'Unknown master data list.');
        $items = $this->search->search($domain, $list, $request->query('q'), $request->query('parent'), $this->tenantId($request), $limit);

        return response()->json(['data' => [
            'domain' => $domain, 'list' => $list, 'label' => $meta['label'], 'parent_list' => $meta['parent_list'], 'selection' => $meta['selection'],
            'allow_other' => $meta['allow_other'], 'catalog_version' => MasterDataCache::version($domain), 'values' => $this->localize($items ?? [], $locale),
        ]])->header('Cache-Control', 'public, max-age=60');
    }

    private function localize(array $values, string $locale): array
    {
        return array_map(function ($v) use ($locale) {
            unset($v['search']);

            return ['display' => $v['label'][$locale] ?? $v['label']['en']] + $v;
        }, $values);
    }

    private function locale(Request $request): string
    {
        $l = strtolower((string) ($request->query('locale') ?? $request->getPreferredLanguage(['en', 'fr']) ?? 'en'));

        return str_starts_with($l, 'fr') ? 'fr' : 'en';
    }

    /** Tenant of the signed-in user (optional on public routes) for ranking and overrides. */
    private function tenantId(Request $request): ?string
    {
        try {
            $user = $request->user() ?? auth('api')->user();
        } catch (Throwable) {
            return null;
        }
        if (! $user || ! method_exists($user, 'memberships')) {
            return null;
        }
        $wanted = $request->header('X-Tenant-Id');

        return $user->memberships()->where('status', 'ACTIVE')->when($wanted, fn ($q) => $q->where('tenant_id', $wanted))->value('tenant_id');
    }
}
