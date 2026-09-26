<?php

declare(strict_types=1);

namespace App\Application\ReferenceDatasets\Http;

use App\Application\ReferenceDatasets\PublicHolidayGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-CAL-001 — yearly public-holiday dataset from calendars.public_holiday_rule (always a DRAFT; maker-checker activation). */
final class PublicHolidayGeneratorController
{
    public function __construct(private readonly PublicHolidayGenerator $generator) {}

    public function preview(Request $r): JsonResponse
    {
        $d = $r->validate(['year' => 'required|integer|between:2000,2100']);

        return response()->json(['data' => $this->generator->preview((int) $d['year'])]);
    }

    public function generate(Request $r): JsonResponse
    {
        $d = $r->validate([
            'year' => 'required|integer|between:2000,2100', 'jurisdiction' => 'nullable|string|size:2',
            'extra' => 'nullable|array|max:20', 'extra.*.code' => 'nullable|string|max:64', 'extra.*.date' => 'required|date_format:Y-m-d',
            'extra.*.label' => 'required|string|max:160', 'extra.*.holiday_type' => 'nullable|in:PUBLIC,RELIGIOUS_MOVABLE,DECREED',
            'extra.*.legal_reference' => 'required|string|max:255',
        ]);
        foreach ($d['extra'] ?? [] as $e) {
            if ((int) substr($e['date'], 0, 4) !== (int) $d['year']) {
                abort(422, 'Extra holiday dates must fall in the requested year.');
            }
        }
        $out = $this->generator->draft((int) $d['year'], $r->user(), strtoupper($d['jurisdiction'] ?? 'CM'), $d['extra'] ?? []);

        return response()->json(['data' => $out['dataset'], 'pending' => $out['pending']], 201);
    }
}
