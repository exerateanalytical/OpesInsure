<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Directory;

use App\Models\Carrier;
use App\Models\InsuranceClass;
use App\Models\Partner;
use Database\Seeders\CameroonInsuranceRegisterSeeder as Register;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Public institution directory for the mobile app (app/institutions/*).
 * Insurers are ACTIVE carriers; brokers are ACTIVE BROKER partners. The
 * official DGTCFM/MINFI 2026 register (29 insurers, 123 brokers) is listed
 * first in regulator order, with canonical IDs and provenance. Only
 * institutional data is exposed; unknown facts are null, never invented.
 * Product families are carrier-published and unverified.
 */
final class PublicInstitutionController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'nullable|in:insurer,broker',
            'branch' => 'nullable|in:IARD,LIFE',
            'q' => 'nullable|string|max:120',
        ]);
        $type = $data['type'] ?? null;

        $insurers = $this->carriers()->get()->map(fn (Carrier $c) => $this->insurerOf($c));
        $brokers = $this->brokers()->get()->map(fn (Partner $p) => $this->brokerOf($p));
        $counts = [
            'insurer' => $insurers->count(),
            'broker' => $brokers->count(),
            'IARD' => $insurers->where('branch', 'IARD')->count(),
            'LIFE' => $insurers->where('branch', 'LIFE')->count(),
        ];

        $rows = collect();
        if ($type === null || $type === 'insurer') {
            $rows = $rows->concat($this->ordered($insurers));
        }
        if ($type === null || $type === 'broker') {
            $rows = $rows->concat($this->ordered($brokers));
        }
        if (! empty($data['branch'])) {
            $rows = $rows->filter(fn (array $r) => $r['type'] === 'insurer' && $r['branch'] === $data['branch']);
        }
        if (filled($data['q'] ?? null)) {
            $rows = $rows->filter(fn (array $r) => $this->matches($r, (string) $data['q']));
        }

        return response()->json(['data' => $rows->values(), 'meta' => ['counts' => $counts, 'source' => $this->source()]]);
    }

    public function show(string $institution): JsonResponse
    {
        abort_unless(Str::isUuid($institution), 404);

        if ($carrier = $this->carriers()->find($institution)) {
            return response()->json(['data' => $this->insurerOf($carrier), 'meta' => ['source' => $this->source()]]);
        }
        if ($broker = $this->brokers()->find($institution)) {
            return response()->json(['data' => $this->brokerOf($broker), 'meta' => ['source' => $this->source()]]);
        }

        abort(404);
    }

    /** GET /public/insurance-classes — official class taxonomy with EN/FR names. */
    public function classes(): JsonResponse
    {
        $classes = InsuranceClass::query()->whereNull('parent_id')->with('children')->orderBy('sort_order')->get()
            ->map(fn (InsuranceClass $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'branch' => $c->branch,
                'name' => $c->name,
                'data_origin' => $c->data_origin,
                'sub_classes' => $c->children->map(fn (InsuranceClass $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values(),
            ]);

        return response()->json(['data' => $classes, 'source' => Register::SOURCE, 'meta' => ['source' => $this->source()]]);
    }

    private function carriers()
    {
        return Carrier::query()->where('status', 'ACTIVE')->with(['party.contacts', 'products' => fn ($q) => $q->where('status', 'ACTIVE')->orderBy('name')]);
    }

    private function brokers()
    {
        return Partner::query()->where('type', 'BROKER')->where('status', 'ACTIVE')->with('party.contacts');
    }

    /** Official register rows first in regulator order, then others by name. */
    private function ordered(Collection $rows): Collection
    {
        [$official, $other] = $rows->partition(fn (array $r) => $r['is_official_register']);

        return $official->sortBy('regulator_sequence')->concat($other->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE))->values();
    }

    private function matches(array $row, string $q): bool
    {
        $q = trim($q);
        if (ctype_digit($q)) {
            return (int) $q === ($row['regulator_sequence'] ?? null);
        }
        $needle = Register::normalize($q);
        $haystack = [$row['name'], $row['legal_name'] ?? null, $row['short_name'] ?? null, $row['canonical_id'] ?? null, $row['code'] ?? null];

        return collect($haystack)->contains(fn ($v) => $v !== null && str_contains(Register::normalize($v), $needle));
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return ['authority' => Register::AUTHORITY, 'reference_year' => Register::YEAR, 'register' => Register::SOURCE, 'label' => 'DGTCFM/MINFI official register 2026'];
    }

    /** @return array<string, mixed> */
    private function provenance(Carrier|Partner $m): array
    {
        return [
            'canonical_id' => $m->canonical_id,
            'legal_name' => $m->legal_name,
            'regulator_sequence' => $m->regulator_sequence,
            'is_official_register' => (bool) $m->is_official_register,
            'is_demo' => (bool) $m->is_demo,
            'data_origin' => $m->data_origin,
            'source_authority' => $m->source_authority,
            'reference_year' => $m->reference_year,
            'regulatory_status' => $m->regulatory_status,
            'licensed' => $m->status === 'ACTIVE' && (! $m->is_official_register || $m->regulatory_status === 'AUTHORIZED'),
        ];
    }

    /** @return array<string, mixed> */
    private function insurerOf(Carrier $c): array
    {
        $name = $c->trade_name ?? $c->party?->display_name ?? $c->cima_code;
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
            'insurer_code' => $c->insurer_code,
            'short_name' => $c->short_name,
            'branch' => $c->licence_branch,
            'product_families' => $c->product_families ?? [],
            'product_families_origin' => $c->product_families_origin,
            'product_families_status' => $c->product_families_status,
        ] + $this->provenance($c);
    }

    /** @return array<string, mixed> */
    private function brokerOf(Partner $p): array
    {
        $name = $p->trade_name ?? $p->party?->display_name ?? 'Broker';

        return [
            'id' => $p->id,
            'type' => 'broker',
            'name' => $name,
            'initials' => $this->initials($name),
            'city' => $p->party?->legal_identity['city'] ?? null,
            'phone' => $p->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value,
            'licence_number' => $p->licence_number,
            'licence_expires_on' => $p->licence_expires_on?->toDateString(),
            'regulator_number' => $p->regulator_sequence,
        ] + $this->provenance($p);
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $name) ?? $name)) ?: [];

        return mb_strtoupper(collect($words)->filter()->take(3)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
