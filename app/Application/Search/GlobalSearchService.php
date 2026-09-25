<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Application\Identity\Rbac\DataScope;
use App\Application\Identity\Rbac\DataScopeResolver;
use App\Application\Risks\VehicleDuplicateGuard;
use App\Models\Claim;
use App\Models\Document;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\RiskAsset;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-SRC-001: global search across customers (parties), policies, claims,
 * quotes, documents, vehicles (plate / VIN) and other insured objects.
 *
 * Security, per entity, fail-closed:
 *  - the caller needs the entity's read permission, except a caller whose
 *    widest scope is OWN (a customer), who searches only their own rows;
 *  - every query goes through DataScopeResolver::apply (tenant filter always,
 *    then OWN / ASSIGNED / TEAM / BRANCH / ORGANIZATION / CARRIER / TENANT);
 *    an entity with no column for the caller's scope yields nothing;
 *  - documents are further narrowed by security level (DocumentAccessPolicy).
 *
 * Engine: PostgreSQL only — ILIKE backed by pg_trgm GIN indexes, ranked by
 * similarity() when pg_trgm is installed (plain recency otherwise).
 */
final class GlobalSearchService
{
    public const TYPES = ['customers', 'policies', 'claims', 'quotes', 'documents', 'vehicles', 'risk_assets'];

    private const PERMISSIONS = [
        'customers' => 'customers.read', 'policies' => 'policies.read', 'claims' => 'claims.view', 'quotes' => 'quotes.read',
        'documents' => 'documents.read', 'vehicles' => 'risk_assets.read', 'risk_assets' => 'risk_assets.read',
    ];

    private ?bool $trgm = null;

    public function __construct(private readonly DataScopeResolver $scopes) {}

    /**
     * @param  list<string>  $types
     * @return array{query:string, scope:?string, results:list<array<string,mixed>>, counts:array<string,int>, searched:list<string>}
     */
    public function search(User $user, string $q, array $types = [], int $perType = 5): array
    {
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');
        $scope = $this->scopes->effectiveScope($user);
        $types = $types === [] ? self::TYPES : array_values(array_intersect(self::TYPES, $types));
        $searched = array_values(array_filter($types, fn ($t) => $this->mayRead($user, $scope, $t)));

        $results = [];
        $counts = [];
        foreach ($searched as $type) {
            $rows = $this->{'search'.str_replace('_', '', ucwords($type, '_'))}($user, $q, $perType);
            $counts[$type] = count($rows);
            array_push($results, ...$rows);
        }
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return ['query' => $q, 'scope' => $scope?->value, 'results' => $results, 'counts' => $counts, 'searched' => $searched];
    }

    private function mayRead(User $user, ?DataScope $scope, string $type): bool
    {
        if ($scope === null || ! $scope->grantsBusinessRows()) {
            return false;
        }
        if ($scope === DataScope::OWN) {
            return $type !== 'customers';
        }

        return $user->hasPermission(self::PERMISSIONS[$type]);
    }

    private function searchCustomers(User $user, string $q, int $n): array
    {
        $like = $this->like($q);
        $query = TenantCustomer::query()->join('parties', 'parties.id', '=', 'tenant_customers.party_id')->whereNull('parties.deleted_at')
            ->where(fn ($w) => $w->where('parties.display_name', 'ilike', $like)->orWhere('tenant_customers.customer_number', 'ilike', $like)
                ->orWhereExists(fn ($e) => $e->from('party_contacts')->whereColumn('party_contacts.party_id', 'parties.id')->where('party_contacts.normalized_value', 'ilike', $like)));
        $this->scopes->apply($query, $user, ['tenant' => 'tenant_customers.tenant_id', 'own' => 'tenant_customers.party_id']);

        return $this->rank($query, ['parties.display_name', 'tenant_customers.customer_number'], $q, $n, 'tenant_customers.created_at', ['tenant_customers.id', 'tenant_customers.party_id', 'parties.display_name', 'tenant_customers.customer_number', 'tenant_customers.status'])
            ->get()
            ->map(fn ($r) => $this->hit('customers', $r->id, $r->display_name, $r->customer_number, $r->status, $r->score, ['party_id' => $r->party_id]))->all();
    }

    private function searchPolicies(User $user, string $q, int $n): array
    {
        $like = $this->like($q);
        $query = Policy::query()->leftJoin('parties', 'parties.id', '=', 'policies.party_id')
            ->where(fn ($w) => $w->where('policies.policy_number', 'ilike', $like)->orWhere('policies.certificate_number', 'ilike', $like)->orWhere('parties.display_name', 'ilike', $like));
        $this->scopes->apply($query, $user, ['tenant' => 'policies.tenant_id', 'own' => 'policies.party_id', 'carrier' => 'policies.carrier_id']);

        return $this->rank($query, ['policies.policy_number', 'policies.certificate_number', 'parties.display_name'], $q, $n, 'policies.created_at', ['policies.id', 'policies.policy_number', 'policies.status', 'parties.display_name'])
            ->get()
            ->map(fn ($r) => $this->hit('policies', $r->id, $r->policy_number ?? $r->id, $r->display_name, $r->status, $r->score))->all();
    }

    private function searchClaims(User $user, string $q, int $n): array
    {
        $like = $this->like($q);
        $query = Claim::query()->join('policies', 'policies.id', '=', 'claims.policy_id')->leftJoin('parties', 'parties.id', '=', 'claims.claimant_party_id')
            ->where(fn ($w) => $w->where('claims.claim_number', 'ilike', $like)->orWhere('claims.carrier_reference', 'ilike', $like)
                ->orWhere('policies.policy_number', 'ilike', $like)->orWhere('parties.display_name', 'ilike', $like));
        $this->scopes->apply($query, $user, ['tenant' => 'claims.tenant_id', 'own' => 'claims.claimant_party_id', 'assigned' => 'claims.assigned_to', 'carrier' => 'policies.carrier_id']);

        return $this->rank($query, ['claims.claim_number', 'claims.carrier_reference', 'policies.policy_number', 'parties.display_name'], $q, $n, 'claims.created_at', ['claims.id', 'claims.claim_number', 'claims.status', 'policies.policy_number', 'parties.display_name'])
            ->get()
            ->map(fn ($r) => $this->hit('claims', $r->id, $r->claim_number ?? $r->id, trim(($r->policy_number ?? '').' '.($r->display_name ?? '')), $r->status, $r->score))->all();
    }

    private function searchQuotes(User $user, string $q, int $n): array
    {
        $like = $this->like($q);
        $prefix = $this->escape(strtolower($q)).'%';
        $query = Quote::query()->leftJoin('parties', 'parties.id', '=', 'quotes.party_id')
            ->where(fn ($w) => $w->whereRaw('quotes.id::text like ?', [$prefix])->orWhere('parties.display_name', 'ilike', $like)->orWhere('quotes.line_code', 'ilike', $like));
        $this->scopes->apply($query, $user, ['tenant' => 'quotes.tenant_id', 'own' => 'quotes.party_id']);

        return $this->rank($query, ['parties.display_name', 'quotes.line_code'], $q, $n, 'quotes.created_at', ['quotes.id', 'quotes.line_code', 'quotes.status', 'parties.display_name'])
            ->get()
            ->map(fn ($r) => $this->hit('quotes', $r->id, $r->line_code.' · '.substr($r->id, 0, 8), $r->display_name, $r->status, $r->score))->all();
    }

    private function searchDocuments(User $user, string $q, int $n): array
    {
        $like = $this->like($q);
        $query = Document::query()
            ->where(fn ($w) => $w->where('documents.document_number', 'ilike', $like)->orWhere('documents.title', 'ilike', $like)->orWhere('documents.subject_label', 'ilike', $like));
        $scope = $this->scopes->effectiveScope($user);
        $levels = $scope === DataScope::OWN ? DocumentAccessPolicy::CUSTOMER_LEVELS : $this->staffLevels($user);
        $query->where(fn ($w) => $w->whereIn('documents.security_level', $levels)->when(in_array('CUSTOMER_PRIVATE', $levels, true), fn ($x) => $x->orWhereNull('documents.security_level')));
        $this->scopes->apply($query, $user, ['tenant' => 'documents.tenant_id', 'own' => 'documents.party_id', 'carrier' => 'documents.issuer_carrier_id']);

        return $this->rank($query, ['documents.document_number', 'documents.title', 'documents.subject_label'], $q, $n, 'documents.created_at', ['documents.id', 'documents.document_number', 'documents.title', 'documents.document_type_code', 'documents.status'])
            ->get()
            ->map(fn ($r) => $this->hit('documents', $r->id, $r->title ?? $r->document_number ?? $r->document_type_code ?? $r->id, $r->document_number, $r->status, $r->score))->all();
    }

    private function searchVehicles(User $user, string $q, int $n): array
    {
        $norm = VehicleDuplicateGuard::normalize($q);
        if ($norm === null || strlen($norm) < 2) {
            return [];
        }
        $plate = "regexp_replace(upper(risk_asset_vehicles.registration_number), '[^A-Z0-9]', '', 'g')";
        $vin = "regexp_replace(upper(risk_asset_vehicles.vin), '[^A-Z0-9]', '', 'g')";
        $like = '%'.$norm.'%';
        $query = RiskAsset::query()->join('risk_asset_vehicles', 'risk_asset_vehicles.risk_asset_id', '=', 'risk_assets.id')
            ->where(fn ($w) => $w->whereRaw("{$plate} like ?", [$like])->orWhereRaw("{$vin} like ?", [$like]));
        $this->scopes->apply($query, $user, ['tenant' => 'risk_assets.tenant_id', 'own' => 'risk_assets.party_id']);
        $score = $this->hasTrgm()
            ? DB::raw("greatest(similarity(coalesce({$plate}, ''), ".DB::getPdo()->quote($norm)."), similarity(coalesce({$vin}, ''), ".DB::getPdo()->quote($norm).'), case when '.$plate.' = '.DB::getPdo()->quote($norm).' or '.$vin.' = '.DB::getPdo()->quote($norm).' then 1 else 0 end) as score')
            : DB::raw('0 as score');

        return $query->select(['risk_assets.id', 'risk_assets.display_name', 'risk_assets.status', 'risk_asset_vehicles.registration_number', 'risk_asset_vehicles.vin', $score])
            ->orderByDesc('score')->orderByDesc('risk_assets.created_at')->limit($n)->get()
            ->map(fn ($r) => $this->hit('vehicles', $r->id, $r->registration_number ?? $r->vin ?? $r->display_name, trim($r->display_name.' '.($r->vin ?? '')), $r->status, (float) $r->score, ['vin' => $r->vin, 'registration_number' => $r->registration_number]))->all();
    }

    private function searchRiskAssets(User $user, string $q, int $n): array
    {
        $like = $this->like($q);
        $query = RiskAsset::query()->where(fn ($w) => $w->where('risk_assets.display_name', 'ilike', $like)->orWhere('risk_assets.external_reference', 'ilike', $like));
        $this->scopes->apply($query, $user, ['tenant' => 'risk_assets.tenant_id', 'own' => 'risk_assets.party_id']);

        return $this->rank($query, ['risk_assets.display_name', 'risk_assets.external_reference'], $q, $n, 'risk_assets.created_at', ['risk_assets.id', 'risk_assets.display_name', 'risk_assets.type', 'risk_assets.status'])
            ->get()
            ->map(fn ($r) => $this->hit('risk_assets', $r->id, $r->display_name, $r->type, $r->status, $r->score))->all();
    }

    /** @return list<string> */
    private function staffLevels(User $user): array
    {
        $levels = ['PUBLIC_VERIFIABLE', 'CUSTOMER_PRIVATE'];
        foreach (['MEDICAL_RESTRICTED' => 'documents.medical.read', 'FINANCIAL_RESTRICTED' => 'documents.financial.read', 'INSURER_CONFIDENTIAL' => 'documents.confidential.read', 'INTERNAL' => 'documents.confidential.read', 'REGULATORY' => 'documents.regulatory.read'] as $level => $perm) {
            if ($user->hasPermission($perm)) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    /** @param list<string> $columns @param list<string> $select */
    private function rank(Builder $query, array $columns, string $q, int $n, string $recency, array $select): Builder
    {
        $query->select($select);
        if ($this->hasTrgm()) {
            $quoted = DB::getPdo()->quote(mb_strtolower($q));
            $parts = array_map(fn ($c) => "similarity(lower(coalesce({$c}::text, '')), {$quoted})", $columns);
            $exact = implode(' or ', array_map(fn ($c) => "lower({$c}::text) = {$quoted}", $columns));
            $query->selectRaw('greatest('.implode(', ', $parts).", case when {$exact} then 1 else 0 end) as score");
        } else {
            $query->selectRaw('0 as score');
        }

        return $query->orderByDesc('score')->orderByDesc($recency)->limit($n);
    }

    private function hit(string $type, string $id, ?string $title, ?string $subtitle, ?string $status, mixed $score, array $extra = []): array
    {
        return ['type' => $type, 'id' => $id, 'title' => (string) $title, 'subtitle' => $subtitle ?: null, 'status' => $status, 'score' => round((float) $score, 4)] + $extra;
    }

    private function like(string $q): string
    {
        return '%'.$this->escape($q).'%';
    }

    private function escape(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);
    }

    private function hasTrgm(): bool
    {
        return $this->trgm ??= DB::table('pg_extension')->where('extname', 'pg_trgm')->exists();
    }
}
