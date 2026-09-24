<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Directory;

use App\Models\Carrier;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public institution directory for the mobile app (app/institutions/*).
 * Insurers are ACTIVE carriers; brokers are ACTIVE BROKER partners. Only
 * institutional (organisation) data is exposed: name, city, switchboard
 * phone and website for carriers, licence reference for brokers.
 */
final class PublicInstitutionController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'nullable|in:insurer,broker']);
        $type = $data['type'] ?? null;

        $rows = collect();
        if ($type === null || $type === 'insurer') {
            $rows = $rows->concat($this->carriers()->get()->map(fn (Carrier $c) => $this->insurerOf($c)));
        }
        if ($type === null || $type === 'broker') {
            $rows = $rows->concat($this->brokers()->get()->map(fn (Partner $p) => $this->brokerOf($p)));
        }

        return response()->json(['data' => $rows->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()]);
    }

    public function show(string $institution): JsonResponse
    {
        abort_unless(Str::isUuid($institution), 404);

        if ($carrier = $this->carriers()->find($institution)) {
            return response()->json(['data' => $this->insurerOf($carrier)]);
        }
        if ($broker = $this->brokers()->find($institution)) {
            return response()->json(['data' => $this->brokerOf($broker)]);
        }

        abort(404);
    }

    private function carriers()
    {
        return Carrier::query()->where('status', 'ACTIVE')->with(['party.contacts', 'products' => fn ($q) => $q->where('status', 'ACTIVE')->orderBy('name')]);
    }

    private function brokers()
    {
        return Partner::query()->where('type', 'BROKER')->where('status', 'ACTIVE')->with('party');
    }

    /** @return array<string, mixed> */
    private function insurerOf(Carrier $c): array
    {
        $name = $c->party?->display_name ?? $c->cima_code;
        $caps = $c->capabilities ?? [];

        return [
            'id' => $c->id,
            'type' => 'insurer',
            'name' => $name,
            'initials' => $this->initials($name),
            'code' => $c->cima_code,
            'city' => $c->party?->legal_identity['city'] ?? null,
            'phone' => $c->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value,
            'website' => $caps['website'] ?? null,
            'products' => $c->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'line_code' => $p->line_code])->unique('name')->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function brokerOf(Partner $p): array
    {
        $name = $p->party?->display_name ?? 'Broker';

        return [
            'id' => $p->id,
            'type' => 'broker',
            'name' => $name,
            'initials' => $this->initials($name),
            'city' => $p->party?->legal_identity['city'] ?? null,
            'licence_number' => $p->licence_number,
            'licence_expires_on' => $p->licence_expires_on?->toDateString(),
        ];
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $name) ?? $name)) ?: [];

        return mb_strtoupper(collect($words)->filter()->take(3)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
