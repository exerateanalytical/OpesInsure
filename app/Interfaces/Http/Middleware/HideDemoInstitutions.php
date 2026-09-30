<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Models\Carrier;
use App\Models\Partner;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public institution directory (GET /public/institutions[/{id}]): demo and
 * synthetic rows (is_demo, or data_origin DEMO_SYNTHETIC) back the demo staff
 * accounts and must never be shown to the public as licensed institutions.
 * The list drops them (and its meta.counts no longer count them); the detail
 * of a demo institution is a 404, exactly like an unknown id.
 */
final class HideDemoInstitutions
{
    public const DEMO_ORIGIN = 'DEMO_SYNTHETIC';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return $response;
        }

        if (array_is_list($data)) {
            $payload['data'] = array_values(array_filter($data, fn ($row) => ! (is_array($row) && self::isDemo($row))));
            if (isset($payload['meta']['counts']) && is_array($payload['meta']['counts'])) {
                $payload['meta']['counts'] = $this->correctedCounts($payload['meta']['counts']);
            }
            $response->setData($payload);

            return $response;
        }

        abort_if(self::isDemo($data), 404);

        return $response;
    }

    /** @param array<string, mixed> $row */
    public static function isDemo(array $row): bool
    {
        return (bool) ($row['is_demo'] ?? false) || ($row['data_origin'] ?? null) === self::DEMO_ORIGIN;
    }

    /**
     * The directory counts every ACTIVE carrier / BROKER partner; take the demo ones back out.
     *
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function correctedCounts(array $counts): array
    {
        $demo = fn ($q) => $q->where('is_demo', true)->orWhere('data_origin', self::DEMO_ORIGIN);
        $carriers = Carrier::query()->where('status', 'ACTIVE')->where($demo)->pluck('licence_branch');
        $brokers = Partner::query()->where('type', 'BROKER')->where('status', 'ACTIVE')->where($demo)->count();

        $minus = [
            'insurer' => $carriers->count(),
            'broker' => $brokers,
            'IARD' => $carriers->filter(fn ($b) => $b === 'IARD')->count(),
            'LIFE' => $carriers->filter(fn ($b) => $b === 'LIFE')->count(),
        ];
        foreach ($minus as $key => $n) {
            if (isset($counts[$key]) && is_int($counts[$key])) {
                $counts[$key] = max(0, $counts[$key] - $n);
            }
        }

        return $counts;
    }
}
