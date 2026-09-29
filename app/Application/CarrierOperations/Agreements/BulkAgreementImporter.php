<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Agreements;

use App\Application\Distribution\SellabilityService;
use App\Application\Import\ImportFileReader;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S10 — bulk carrier–broker agreement setup. Rows (CSV/XLSX, read by ImportFileReader) are resolved against existing
 * carriers (brand_short_name / insurer_code / short_name / cima_code / name), brokers and agents (legal name, display
 * name or licence number) and the carrier's products; valid files create DRAFT agreements through
 * CarrierBrokerAgreementService (create + setProduct — no parallel write path). A batch is then submitted, and
 * activated by a different user (CarrierBrokerAgreementService::transition keeps its own maker-checker).
 *
 * Scope: ['carrier' => ?id, 'tenant' => ?id]. carrier = insurer portal (rows must name the caller's own carrier);
 * tenant = non-platform staff (brokers must belong to the tenant, as CarrierBrokerAgreementController::authorizePartner).
 *
 * Columns: insurer, broker, lines ("MOTOR;HOME"), products (optional product codes), commission ("MOTOR=15;HOME=12.5",
 * percent per line), effective_from, effective_until, settlement_terms, permissions (optional, default "quote;bind"),
 * agreement_number (optional), source_document (optional), territories, channels (optional, ";"-separated).
 */
final class BulkAgreementImporter
{
    public const COLUMNS = ['insurer', 'broker', 'lines', 'products', 'commission', 'effective_from', 'effective_until', 'settlement_terms', 'permissions', 'agreement_number', 'source_document', 'territories', 'channels'];

    private const PERMISSIONS = ['quote' => 'can_quote', 'bind' => 'can_bind', 'collect_premium' => 'can_collect_premium', 'issue_documents' => 'can_issue_documents', 'service_policy' => 'can_service_policies', 'assist_claims' => 'can_assist_claims'];

    public function __construct(private readonly CarrierBrokerAgreementService $agreements, private readonly ImportFileReader $reader) {}

    /** @return list<array<string, string|null>> */
    public function read(string $path, string $fileName): array
    {
        return $this->reader->read($path, ImportFileReader::format($fileName))['rows'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row:int, errors:list<string>, data:?array<string,mixed>, label:string}>
     */
    public function validate(array $rows, array $scope): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($rows) as $i => $raw) {
            $r = array_change_key_case(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $raw));
            $errors = [];
            $carrier = $this->carrier((string) ($r['insurer'] ?? ''), $errors);
            $partner = $this->partner((string) ($r['broker'] ?? ''), $errors);
            if ($carrier && ! empty($scope['carrier']) && $carrier->id !== $scope['carrier']) {
                $errors[] = __('bulk_agreements.errors.not_own_carrier');
            }
            if ($partner && ! empty($scope['tenant']) && $partner->tenant_id !== $scope['tenant']) {
                $errors[] = __('bulk_agreements.errors.broker_out_of_scope');
            }
            $from = $this->date($r['effective_from'] ?? null);
            $until = $this->date($r['effective_until'] ?? null);
            if ($from === null) {
                $errors[] = __('bulk_agreements.errors.effective_from');
            }
            if (filled($r['effective_until'] ?? null) && $until === null) {
                $errors[] = __('bulk_agreements.errors.effective_until');
            }
            if ($from && $until && $until < $from) {
                $errors[] = __('bulk_agreements.errors.period');
            }
            $lines = $this->lines($carrier, (string) ($r['lines'] ?? ''), (string) ($r['products'] ?? ''), (string) ($r['commission'] ?? ''), $errors);
            $flags = $this->flags((string) ($r['permissions'] ?? ''), $errors);
            $number = filled($r['agreement_number'] ?? null) ? (string) $r['agreement_number'] : null;
            if ($number !== null && (strlen($number) > 80 || DB::table('carrier_broker_agreements')->where('agreement_number', $number)->exists())) {
                $errors[] = __('bulk_agreements.errors.number_taken', ['number' => $number]);
            }
            if ($carrier && $partner) {
                $key = $carrier->id.'|'.$partner->id;
                if (isset($seen[$key])) {
                    $errors[] = __('bulk_agreements.errors.duplicate_row', ['row' => $seen[$key]]);
                }
                $seen[$key] = $i + 2;
                if (DB::table('carrier_broker_agreements')->where(['carrier_id' => $carrier->id, 'partner_id' => $partner->id])->whereIn('status', ['DRAFT', 'ACTIVE', 'SUSPENDED'])->exists()) {
                    $errors[] = __('bulk_agreements.errors.exists');
                }
            }
            $out[] = [
                'row' => $i + 2, // header is line 1
                'label' => trim(($carrier?->label ?? ($r['insurer'] ?? '?')).' × '.($partner?->label ?? ($r['broker'] ?? '?'))),
                'errors' => $errors,
                'data' => $errors !== [] ? null : [
                    'carrier_id' => $carrier->id, 'partner_id' => $partner->id, 'effective_from' => $from, 'effective_until' => $until,
                    'agreement_number' => $number, 'lines' => $lines, 'flags' => $flags,
                    'settlement_terms' => filled($r['settlement_terms'] ?? null) ? ['terms' => (string) $r['settlement_terms']] : null,
                    'source_document' => filled($r['source_document'] ?? null) ? mb_substr((string) $r['source_document'], 0, 255) : null,
                    'territories' => $this->list((string) ($r['territories'] ?? '')), 'channels' => $this->list((string) ($r['channels'] ?? '')),
                ],
            ];
        }

        return $out;
    }

    /** All-or-nothing: any row error refuses the whole file. Returns the batch id. */
    public function createDrafts(User $maker, array $rows, array $scope, ?string $fileName = null): string
    {
        $checked = $this->validate($rows, $scope);
        if ($checked === []) {
            throw ValidationException::withMessages(['file' => __('bulk_agreements.errors.empty')]);
        }
        if (collect($checked)->contains(fn ($c) => $c['errors'] !== [])) {
            throw ValidationException::withMessages(['file' => __('bulk_agreements.errors.fix_first')]);
        }

        return DB::transaction(function () use ($maker, $checked, $scope, $fileName): string {
            $ids = [];
            foreach ($checked as $c) {
                $d = $c['data'];
                $a = $this->agreements->create($maker, [
                    'carrier_id' => $d['carrier_id'], 'partner_id' => $d['partner_id'],
                    'agreement_number' => $d['agreement_number'] ?? 'BLK-'.now()->format('ymd').'-'.strtoupper(Str::random(8)),
                    'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'],
                    'territories' => $d['territories'], 'channels' => $d['channels'],
                    'settlement_terms' => $d['settlement_terms'], 'source_document' => $d['source_document'],
                ]);
                foreach ($d['lines'] as $line) {
                    $this->agreements->setProduct($maker, $a->id, [...$line, ...$d['flags'], 'status' => 'ACTIVE', 'reason' => 'Bulk agreement import']);
                }
                $ids[] = $a->id;
            }
            $id = (string) Str::uuid();
            DB::table('agreement_bulk_imports')->insert(['id' => $id, 'carrier_scope_id' => $scope['carrier'] ?? null, 'file_name' => $fileName ? mb_substr($fileName, 0, 255) : null,
                'status' => 'DRAFT', 'agreement_ids' => json_encode($ids), 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    public function submit(User $actor, string $batchId, array $scope): object
    {
        $b = $this->batch($batchId, $scope);
        if ($b->status !== 'DRAFT') {
            throw ValidationException::withMessages(['batch' => __('bulk_agreements.errors.not_draft')]);
        }
        DB::table('agreement_bulk_imports')->where('id', $b->id)->update(['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_at' => now()]);

        return $this->batch($batchId, $scope);
    }

    /** Checker step: never the maker (nor the submitter). Each agreement goes through the service transition; failures are kept per agreement. */
    public function activate(User $checker, string $batchId, string $reason, array $scope): object
    {
        $b = $this->batch($batchId, $scope);
        if (! in_array($b->status, ['SUBMITTED', 'PARTIAL'], true)) {
            throw ValidationException::withMessages(['batch' => __('bulk_agreements.errors.not_submitted')]);
        }
        if ($checker->id === $b->created_by || $checker->id === $b->submitted_by) {
            throw ValidationException::withMessages(['actor' => __('bulk_agreements.errors.maker_checker')]);
        }
        $errors = [];
        foreach ((array) json_decode((string) $b->agreement_ids, true) as $id) {
            if (DB::table('carrier_broker_agreements')->where('id', $id)->value('status') !== 'DRAFT') {
                continue;
            }
            try {
                $this->agreements->transition($checker, $id, 'ACTIVE', $reason);
            } catch (ValidationException $e) {
                $errors[$id] = collect($e->errors())->flatten()->implode(' ');
            }
        }
        DB::table('agreement_bulk_imports')->where('id', $b->id)->update(['status' => $errors === [] ? 'ACTIVATED' : 'PARTIAL', 'activation_errors' => json_encode((object) $errors),
            'activated_by' => $checker->id, 'activated_at' => now(), 'updated_at' => now()]);

        return $this->batch($batchId, $scope);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public function batches(array $scope)
    {
        return DB::table('agreement_bulk_imports')->when($scope['carrier'] ?? null, fn ($q, $c) => $q->where('carrier_scope_id', $c))
            ->when(! empty($scope['tenant']), fn ($q) => $q->whereIn('created_by', DB::table('tenant_memberships')->where('tenant_id', $scope['tenant'])->select('user_id')))
            ->orderByDesc('created_at')->limit(50)->get();
    }

    public function batch(string $id, array $scope): object
    {
        return $this->batches($scope)->firstWhere('id', $id) ?? DB::table('agreement_bulk_imports')->where('id', $id)
            ->when($scope['carrier'] ?? null, fn ($q, $c) => $q->where('carrier_scope_id', $c))
            ->when(! empty($scope['tenant']), fn ($q) => $q->whereIn('created_by', DB::table('tenant_memberships')->where('tenant_id', $scope['tenant'])->select('user_id')))
            ->first() ?? abort(404);
    }

    /**
     * Coverage matrix: brokers (and supervising brokerages of agents) × insurers, ACTIVE agreement or not, plus the
     * brokers/agents whose sellable catalogue is empty today (SellabilityService, the same engine as quoting).
     *
     * @return array{carriers: array<string,string>, rows: list<array{id:string,label:string,type:string,cells:array<string,?string>,sellable:int}>}
     */
    public function coverage(array $scope): array
    {
        $carriers = $this->carrierRows()->when($scope['carrier'] ?? null, fn ($c, $id) => $c->where('id', $id))->mapWithKeys(fn ($c) => [$c->id => $c->label])->all();
        $partners = DB::table('partners as p')->leftJoin('parties as y', 'y.id', '=', 'p.party_id')->whereIn('p.type', ['BROKER', 'AGENT'])
            ->when($scope['tenant'] ?? null, fn ($q, $t) => $q->where('p.tenant_id', $t))->orderByRaw('COALESCE(p.legal_name, y.display_name)')
            ->limit(500)->get(['p.*', DB::raw('COALESCE(p.legal_name, y.display_name) as label')]);
        $sellability = app(SellabilityService::class);
        $on = now()->toDateString();
        $products = DB::table('insurance_products')->where('status', 'ACTIVE')->when($scope['carrier'] ?? null, fn ($q, $c) => $q->where('carrier_id', $c))->get(['id', 'carrier_id']);
        $rows = [];
        foreach ($partners as $p) {
            [$seller] = $sellability->seller($p, $on);
            $agreements = DB::table('carrier_broker_agreements')->where('partner_id', $seller->id)->whereIn('carrier_id', array_keys($carriers))->get(['carrier_id', 'status', 'effective_from', 'effective_until']);
            $cells = [];
            foreach ($carriers as $cid => $_) {
                $mine = $agreements->where('carrier_id', $cid);
                $active = $mine->first(fn ($a) => $a->status === 'ACTIVE' && $a->effective_from <= $on && ($a->effective_until === null || $a->effective_until >= $on));
                $cells[$cid] = $active ? 'ACTIVE' : ($mine->first()?->status);
            }
            $withAgreement = $products->whereIn('carrier_id', array_keys(array_filter($cells, fn ($s) => $s === 'ACTIVE')));
            $sellable = 0;
            foreach ($withAgreement as $prod) {
                if ($sellability->check($prod->id, 'quote', ['partner_id' => $p->id, 'on' => $on])['sellable']) {
                    $sellable++;
                }
            }
            $rows[] = ['id' => $p->id, 'label' => (string) ($p->label ?? $p->id), 'type' => $p->type, 'cells' => $cells, 'sellable' => $sellable];
        }

        return ['carriers' => $carriers, 'rows' => $rows];
    }

    private function carrierRows()
    {
        return DB::table('carriers as c')->leftJoin('parties as y', 'y.id', '=', 'c.party_id')
            ->get(['c.id', 'c.cima_code', 'c.insurer_code', 'c.short_name', 'c.brand_short_name', 'c.legal_name', 'y.display_name',
                DB::raw('COALESCE(c.brand_short_name, c.short_name, y.display_name, c.cima_code) as label')]);
    }

    private function carrier(string $key, array &$errors): ?object
    {
        if ($key === '') {
            $errors[] = __('bulk_agreements.errors.insurer_missing');

            return null;
        }
        $k = mb_strtolower($key);
        $hits = $this->carrierRowsCached()->filter(fn ($c) => in_array($k, array_map(fn ($v) => mb_strtolower((string) $v), [$c->brand_short_name, $c->insurer_code, $c->short_name, $c->cima_code, $c->legal_name, $c->display_name]), true));
        if ($hits->count() !== 1) {
            $errors[] = __($hits->isEmpty() ? 'bulk_agreements.errors.insurer_unknown' : 'bulk_agreements.errors.insurer_ambiguous', ['value' => $key]);

            return null;
        }

        return $hits->first();
    }

    private $carrierCache = null;

    private function carrierRowsCached()
    {
        return $this->carrierCache ??= $this->carrierRows();
    }

    private function partner(string $key, array &$errors): ?object
    {
        if ($key === '') {
            $errors[] = __('bulk_agreements.errors.broker_missing');

            return null;
        }
        $k = mb_strtolower($key);
        $hits = DB::table('partners as p')->leftJoin('parties as y', 'y.id', '=', 'p.party_id')->whereIn('p.type', ['BROKER', 'AGENT'])
            ->where(fn ($q) => $q->whereRaw('LOWER(p.legal_name) = ?', [$k])->orWhereRaw('LOWER(y.display_name) = ?', [$k])->orWhereRaw('LOWER(p.licence_number) = ?', [$k]))
            ->limit(3)->get(['p.id', 'p.tenant_id', 'p.type', DB::raw('COALESCE(p.legal_name, y.display_name) as label')]);
        if ($hits->count() !== 1) {
            $errors[] = __($hits->isEmpty() ? 'bulk_agreements.errors.broker_unknown' : 'bulk_agreements.errors.broker_ambiguous', ['value' => $key]);

            return null;
        }

        return $hits->first();
    }

    /** @return list<array{line_code:string, insurance_product_id:?string, commission_basis_points:?int}> */
    private function lines(?object $carrier, string $lines, string $products, string $commission, array &$errors): array
    {
        $codes = array_map('strtoupper', $this->list($lines));
        $productLines = [];
        foreach ($this->list($products) as $code) {
            $p = $carrier ? DB::table('insurance_products')->where('carrier_id', $carrier->id)->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->orderByDesc('version')->first(['id', 'line_code']) : null;
            if ($carrier && ! $p) {
                $errors[] = __('bulk_agreements.errors.product_unknown', ['value' => $code]);
            } elseif ($p) {
                $productLines[] = ['line_code' => $p->line_code, 'insurance_product_id' => $p->id];
            }
        }
        if ($codes === [] && $productLines === []) {
            $errors[] = __('bulk_agreements.errors.lines_missing');
        }
        if ($carrier) {
            $known = DB::table('insurance_products')->where('carrier_id', $carrier->id)->distinct()->pluck('line_code')->map(fn ($c) => strtoupper((string) $c))->all();
            foreach ($codes as $c) {
                if (! in_array($c, $known, true)) {
                    $errors[] = __('bulk_agreements.errors.line_unknown', ['value' => $c]);
                }
            }
        }
        $rates = [];
        foreach ($this->list($commission) as $pair) {
            if (! preg_match('/^([A-Za-z0-9_\-]+)\s*[=:]\s*([0-9]+(?:[.,][0-9]+)?)\s*%?$/', $pair, $m)) {
                $errors[] = __('bulk_agreements.errors.commission_format', ['value' => $pair]);

                continue;
            }
            $pct = (float) str_replace(',', '.', $m[2]);
            if ($pct > 100) {
                $errors[] = __('bulk_agreements.errors.commission_range', ['value' => $pair]);

                continue;
            }
            $rates[strtoupper($m[1])] = (int) round($pct * 100);
        }
        $allLines = array_unique([...$codes, ...array_map(fn ($l) => strtoupper($l['line_code']), $productLines)]);
        foreach (array_keys($rates) as $l) {
            if (! in_array($l, $allLines, true)) {
                $errors[] = __('bulk_agreements.errors.commission_line', ['value' => $l]);
            }
        }
        $out = [];
        foreach ($codes as $c) {
            $out[] = ['line_code' => $c, 'insurance_product_id' => null, 'commission_basis_points' => $rates[$c] ?? null];
        }
        foreach ($productLines as $l) {
            $out[] = [...$l, 'commission_basis_points' => $rates[strtoupper($l['line_code'])] ?? null];
        }

        return $out;
    }

    private function flags(string $permissions, array &$errors): array
    {
        $list = $permissions === '' ? ['quote', 'bind'] : array_map('strtolower', $this->list($permissions));
        $flags = array_fill_keys(array_values(self::PERMISSIONS), false);
        foreach ($list as $p) {
            if (! isset(self::PERMISSIONS[$p])) {
                $errors[] = __('bulk_agreements.errors.permission_unknown', ['value' => $p]);

                continue;
            }
            $flags[self::PERMISSIONS[$p]] = true;
        }
        if ($flags['can_bind'] && ! $flags['can_quote']) {
            $errors[] = __('bulk_agreements.errors.bind_needs_quote');
        }

        return $flags;
    }

    /** @return list<string> */
    private function list(string $v): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;|\n]/', $v) ?: []), fn ($s) => $s !== ''));
    }

    private function date(mixed $v): ?string
    {
        if (blank($v)) {
            return null;
        }
        if (is_numeric($v)) { // XLSX serial date
            return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, (string) $v);
            if ($d && $d->format($f) === (string) $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
