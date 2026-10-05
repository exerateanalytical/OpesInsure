<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Directory;

use App\Models\Carrier;
use App\Models\Directory\InstitutionOffice;
use App\Models\Directory\InstitutionProfile;
use App\Models\Directory\InstitutionVerificationLabel;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Models\InsuranceClass;
use App\Models\Letterhead\LetterheadAsset;
use App\Models\Partner;
use Database\Seeders\CameroonInsuranceRegisterSeeder as Register;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    /** @var array<string, array<string, mixed>> Directory data keyed by carrier id (institution_profiles/offices, HEAD_OFFICE address). */
    private array $directory = [];

    /** @var array<string, array{en: string, fr: string}> Admin-controlled verification labels. */
    private array $labels = [];

    /** @var array<string, LetterheadAsset> ACTIVE letterhead version keyed by carrier id. */
    private array $letterheads = [];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'nullable|in:insurer,broker',
            'branch' => 'nullable|in:IARD,LIFE',
            'q' => 'nullable|string|max:120',
        ]);
        $type = $data['type'] ?? null;

        $carriers = $this->carriers()->get();
        $this->loadDirectory($carriers);
        $insurers = $carriers->map(fn (Carrier $c) => $this->insurerOf($c));
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
            $this->loadDirectory(collect([$carrier]));

            return response()->json(['data' => $this->insurerOf($carrier), 'meta' => ['source' => $this->source()]
                + ($carrier->is_official_register ? ['claim_url' => url('/organisations/claim/insurer/'.$carrier->id)] : [])]);
        }
        if ($broker = $this->brokers()->find($institution)) {
            return response()->json(['data' => $this->brokerOf($broker) + $this->affiliationsOf($broker), 'meta' => ['source' => $this->source()]
                + ($broker->is_official_register ? ['claim_url' => url('/organisations/claim/broker/'.$broker->id)] : [])]);
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

    /**
     * Official register rows first in regulator order, then others by name. Register order is
     * kept even for featured brokers; clients put `featured` rows first themselves.
     */
    private function ordered(Collection $rows): Collection
    {
        [$official, $other] = $rows->partition(fn (array $r) => $r['is_official_register']);

        return $official->sortBy(fn (array $r) => [$r['display_order'] ?? PHP_INT_MAX, $r['regulator_sequence']])->values()->concat($other->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE))->values();
    }

    /**
     * Insurers a broker is appointed by and the products it may offer, from its ACTIVE,
     * in-period, non-demo carrier_broker_agreements. A line without a product id covers every
     * ACTIVE product of that carrier in the line. Empty lists when nothing is recorded.
     *
     * @return array{affiliated_insurers: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    private function affiliationsOf(Partner $p): array
    {
        $today = now()->toDateString();
        $agreements = DB::table('carrier_broker_agreements')
            ->where('partner_id', $p->id)->where('status', 'ACTIVE')->where('is_demo', false)
            ->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->get(['id', 'carrier_id']);
        if ($agreements->isEmpty()) {
            return ['affiliated_insurers' => [], 'products' => []];
        }

        $lines = DB::table('carrier_broker_agreement_products')->whereIn('agreement_id', $agreements->pluck('id'))->where('status', 'ACTIVE')
            ->get(['agreement_id', 'line_code', 'insurance_product_id']);
        $carrierOf = $agreements->pluck('carrier_id', 'id');
        $carriers = $this->carriers()->whereIn('id', $agreements->pluck('carrier_id')->unique())->get()->keyBy('id');
        $this->loadDirectory($carriers->values());

        $insurers = [];
        $products = [];
        foreach ($lines as $line) {
            $carrier = $carriers->get($carrierOf[$line->agreement_id] ?? null);
            if (! $carrier) {
                continue;
            }
            $insurer = $this->insurerOf($carrier);
            $insurers[$carrier->id] ??= ['id' => $carrier->id, 'name' => $insurer['name'], 'initials' => $insurer['initials'], 'logo_url' => $insurer['logo_url'], 'lines' => []];
            $insurers[$carrier->id]['lines'][$line->line_code] = $line->line_code;
            $offered = $carrier->products->filter(fn ($prod) => $line->insurance_product_id
                ? $prod->id === $line->insurance_product_id
                : $prod->line_code === $line->line_code);
            foreach ($offered as $prod) {
                $products[$prod->id] = ['id' => $prod->id, 'name' => $prod->name, 'line_code' => $prod->line_code, 'carrier_id' => $carrier->id, 'carrier_name' => $insurer['name'], 'carrier_short_name' => $insurer['short_name']];
            }
        }

        return [
            'affiliated_insurers' => collect($insurers)->map(fn ($i) => ['lines' => array_values($i['lines'])] + $i)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'products' => collect($products)->sortBy([['line_code', 'asc'], ['name', 'asc']])->values()->all(),
        ];
    }

    /** Brokers the platform features (owner, 2026-09-28), matched on the normalized register name. */
    private const FEATURED_BROKERS = ['ASSUR EXPERT D&G SARL'];

    private function isFeatured(Partner $p): bool
    {
        $names = array_map(fn ($n) => Register::normalize($n), self::FEATURED_BROKERS);

        return collect([$p->trade_name, $p->legal_name, $p->party?->display_name])->filter()
            ->contains(fn ($n) => in_array(Register::normalize($n), $names, true));
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
        $d = $this->directory[$c->id] ?? null;

        return [
            'id' => $c->id,
            'type' => 'insurer',
            'name' => $name,
            'initials' => $this->initials($name),
            'code' => $c->cima_code,
            'city' => $d['head_office']['city'] ?? $c->party?->legal_identity['city'] ?? null,
            'phone' => $d['phones'][0] ?? $c->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value,
            'email' => $d['emails'][0] ?? null,
            'website' => $d['website'] ?? $caps['website'] ?? null,
            'products' => $c->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'line_code' => $p->line_code])->unique('name')->values(),
            'insurer_code' => $c->insurer_code,
            'short_name' => $c->brand_short_name ?: $c->short_name,
            'register_short_name' => $c->short_name,
            'display_order' => $c->display_order,
            'branch' => $c->licence_branch,
            'product_families' => $c->product_families ?? [],
            'product_families_origin' => $c->product_families_origin,
            'product_families_status' => $c->product_families_status,
            'contacts' => ['phones' => $d['phones'] ?? [], 'emails' => $d['emails'] ?? [], 'website' => $d['website'] ?? $caps['website'] ?? null, 'po_box' => $d['po_box'] ?? null],
            'head_office' => $d['head_office'] ?? null,
            'branches' => $d['branches'] ?? [],
            'branch_count' => count($d['branches'] ?? []),
            'verification_status' => $d['verification_status'] ?? null,
            'verification_label' => isset($d['verification_status']) ? ($this->labels[$d['verification_status']] ?? null) : null,
            'verified_at' => $d['verified_at'] ?? null,
            'sources' => $d['sources'] ?? [],
            'directory_id' => $d['directory_id'] ?? null,
            // Admin-uploaded, authorized artwork only: logo_url is set only when marked public-display.
            'logo_url' => LetterheadResolver::publicLogoUrl($this->letterheads[$c->id] ?? null),
            'letterhead_available' => isset($this->letterheads[$c->id]),
            // Legal footer lines (registered address, RCCM, NIU, licence ref.) as entered by an admin; only for public-display letterheads.
            'legal_footer' => ($lh = $this->letterheads[$c->id] ?? null) && $lh->public_display ? $lh->footerLines() : [],
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
            'featured' => $this->isFeatured($p),
            // Broker register rows are not linked to an organisation letterhead yet.
            'logo_url' => null,
            'letterhead_available' => false,
            'legal_footer' => [],
        ] + $this->provenance($p);
    }

    /** Batch-loads directory profiles, offices and HEAD_OFFICE addresses for the given carriers. */
    private function loadDirectory(Collection $carriers): void
    {
        $ids = $carriers->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        $this->labels = InstitutionVerificationLabel::map();
        $this->letterheads = LetterheadAsset::where('owner_type', 'CARRIER')->where('status', 'ACTIVE')->whereIn('carrier_id', $ids)
            ->orderBy('version')->get()->keyBy('carrier_id')->all();
        $profiles = InstitutionProfile::whereIn('carrier_id', $ids)->get()->keyBy('carrier_id');
        $offices = InstitutionOffice::whereIn('carrier_id', $ids)->orderBy('sort_order')->orderBy('name')->get()->groupBy('carrier_id');
        $addresses = DB::table('party_addresses')->where('type', 'HEAD_OFFICE')->whereIn('party_id', $carriers->pluck('party_id')->filter()->all())
            ->get(['party_id', 'city', 'line1'])->keyBy('party_id');

        foreach ($carriers as $c) {
            $p = $profiles->get($c->id);
            $a = $c->party_id ? $addresses->get($c->party_id) : null;
            if (! $p && ! $a && ! $offices->has($c->id)) {
                continue;
            }
            $this->directory[$c->id] = [
                'directory_id' => $p?->directory_id,
                'website' => $p?->website,
                'po_box' => $p?->po_box,
                'phones' => $p?->phones ?? [],
                'emails' => $p?->emails ?? [],
                'sources' => $p?->sources ?? [],
                'verification_status' => $p?->verification_status,
                'verified_at' => $p?->verified_at?->toDateString(),
                'head_office' => $a || $p ? ['city' => $a?->city, 'address' => $a?->line1, 'po_box' => $p?->po_box] : null,
                'branches' => ($offices->get($c->id) ?? collect())->map(fn (InstitutionOffice $o) => [
                    'id' => $o->id, 'name' => $o->name, 'type' => $o->office_type, 'city' => $o->city, 'address' => $o->address, 'phone' => $o->phone,
                ])->values()->all(),
            ];
        }
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $name) ?? $name)) ?: [];

        return mb_strtoupper(collect($words)->filter()->take(3)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
