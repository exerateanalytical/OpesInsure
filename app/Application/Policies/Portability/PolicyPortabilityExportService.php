<?php

declare(strict_types=1);

namespace App\Application\Policies\Portability;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Models\Policy;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-POL-009 / ICE gap 42 — policy portability export pack.
 *
 * The pack is "chronology plus terms plus documents" (ICE spec §E, gaps
 * 13/32/36/38/42): every policy_versions row with its structured parties,
 * risks, coverages and limits (Batch 7C), the servicing history, approved
 * servicing transactions and the document register (numbers + sha256, not
 * the bytes). It is built only against a recorded consent/legal basis
 * reference, hashed canonically (pack_sha256), summarised as a PDF, and
 * registered append-only with audit + outbox.
 */
final class PolicyPortabilityExportService
{
    public const SCHEMA_VERSION = 1;

    public const PURPOSES = ['CUSTOMER_REQUEST', 'INTERMEDIARY_TRANSFER', 'CARRIER_TRANSFER', 'REGULATOR_REQUEST'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox, private readonly CanonicalJson $json) {}

    public function export(Policy $policy, string $purpose, string $consentReference, ?string $recipient, User $actor): object
    {
        if (! DB::table('policy_versions')->where('policy_id', $policy->id)->exists()) {
            throw ValidationException::withMessages(['policy' => ['This policy has no recorded chronology yet; it cannot be exported.']]);
        }
        $pack = $this->build($policy);
        $packHash = $this->json->hash($pack);
        $id = (string) Str::uuid();
        $pdf = Pdf::loadHTML($this->html($pack, $packHash, $id))->output();
        $path = "portability/{$policy->tenant_id}/{$policy->id}/{$id}.pdf";
        Storage::disk('local')->put($path, $pdf);

        return DB::transaction(function () use ($policy, $purpose, $consentReference, $recipient, $actor, $pack, $packHash, $id, $path, $pdf) {
            DB::table('policy_portability_exports')->insert([
                'id' => $id, 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'party_id' => $policy->party_id,
                'purpose' => $purpose, 'consent_reference' => $consentReference, 'recipient' => $recipient, 'schema_version' => self::SCHEMA_VERSION,
                'pack' => json_encode($pack, JSON_THROW_ON_ERROR), 'pack_sha256' => $packHash, 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf),
                'requested_by' => $actor->id, 'created_at' => now(),
            ]);
            $this->audit->record('policy.portability.exported', 'policy', $policy->id, ['export_id' => $id, 'purpose' => $purpose, 'consent_reference' => $consentReference, 'recipient' => $recipient, 'pack_sha256' => $packHash]);
            $this->outbox->record('policy.portability.exported', 'policy', $policy->id, ['export_id' => $id, 'purpose' => $purpose, 'party_id' => $policy->party_id, 'pack_sha256' => $packHash]);

            return DB::table('policy_portability_exports')->find($id);
        });
    }

    /** @return array<string, mixed> */
    public function build(Policy $policy): array
    {
        $versions = DB::table('policy_versions')->where('policy_id', $policy->id)->orderBy('version_no')->get();
        $vids = $versions->pluck('id');
        $by = fn (string $table, array $cols) => DB::table($table)->whereIn('policy_version_id', $vids)->orderBy('created_at')->orderBy('id')->get($cols)->groupBy('policy_version_id');
        $parties = $by('policy_parties', ['policy_version_id', 'role', 'party_id', 'display_name', 'allocation_bp']);
        $risks = $by('policy_risks', ['policy_version_id', 'risk_type', 'risk_asset_id', 'display_name', 'facts', 'facts_hash']);
        $coverages = $by('policy_coverages', ['policy_version_id', 'coverage_code', 'name', 'mandatory', 'optional', 'limit_minor', 'deductible_minor', 'premium_minor', 'currency', 'starts_at', 'ends_at']);
        $limits = $by('policy_limits', ['policy_version_id', 'limit_type', 'amount_minor', 'consumed_minor', 'reserved_minor', 'currency']);
        $strip = fn ($rows) => collect($rows)->map(function ($r) {
            $a = (array) $r;
            unset($a['policy_version_id']);
            foreach (['facts', 'name'] as $k) {
                if (isset($a[$k]) && is_string($a[$k])) {
                    $a[$k] = json_decode($a[$k], true);
                }
            }

            return $a;
        })->values()->all();
        $ts = fn ($v) => $v ? CarbonImmutable::parse($v)->toIso8601String() : null;

        return [
            'schema' => 'opesinsure.policy_portability_pack', 'schema_version' => self::SCHEMA_VERSION,
            'policy' => [
                'id' => $policy->id, 'policy_number' => $policy->policy_number, 'certificate_number' => $policy->certificate_number, 'status' => $policy->status,
                'issuing_carrier_id' => $policy->carrier_id, 'servicing_carrier_id' => $policy->servicing_carrier_id ?? $policy->carrier_id,
                'servicing_partner_id' => $policy->servicing_partner_id, 'policyholder_party_id' => $policy->party_id,
                'coverage_starts_at' => $ts($policy->coverage_starts_at), 'coverage_ends_at' => $ts($policy->coverage_ends_at),
                'issued_at' => $ts($policy->issued_at), 'terms_hash' => $policy->terms_hash,
            ],
            'chronology' => $versions->map(fn ($v) => [
                'version_no' => (int) $v->version_no, 'kind' => $v->kind, 'valid_from' => $ts($v->valid_from), 'valid_to' => $ts($v->valid_to),
                'recorded_at' => $ts($v->recorded_at), 'superseded_at' => $ts($v->superseded_at), 'snapshot_hash' => $v->snapshot_hash, 'terms_hash' => $v->terms_hash,
                'snapshot' => json_decode($v->snapshot, true),
                'parties' => $strip($parties[$v->id] ?? []), 'risks' => $strip($risks[$v->id] ?? []),
                'coverages' => $strip($coverages[$v->id] ?? []), 'limits' => $strip($limits[$v->id] ?? []),
            ])->values()->all(),
            'transactions' => DB::table('policy_transactions')->where('policy_id', $policy->id)->where('status', 'APPROVED')->orderBy('effective_at')
                ->get(['transaction_number', 'type', 'effective_at', 'premium_delta_minor', 'currency', 'reason_code', 'approved_at'])
                ->map(fn ($t) => ['transaction_number' => $t->transaction_number, 'type' => $t->type, 'effective_at' => $ts($t->effective_at), 'premium_delta_minor' => (int) $t->premium_delta_minor, 'currency' => $t->currency, 'reason_code' => $t->reason_code, 'approved_at' => $ts($t->approved_at)])->all(),
            'servicing_history' => DB::table('policy_servicing_events')->where('policy_id', $policy->id)->orderBy('occurred_at')->get()
                ->map(fn ($e) => ['scope' => $e->scope, 'from_id' => $e->from_id, 'to_id' => $e->to_id, 'effective_at' => $ts($e->effective_at), 'transfer_id' => $e->transfer_id])->all(),
            'documents' => DB::table('documents')->where('policy_id', $policy->id)->orderBy('created_at')->orderBy('id')->get()
                ->map(fn ($d) => ['document_id' => $d->id, 'document_number' => $d->document_number ?? null, 'type' => $d->document_type_code ?? $d->category, 'mime_type' => $d->mime_type, 'sha256' => $d->sha256, 'created_at' => $ts($d->created_at)])->all(),
        ];
    }

    private function html(array $pack, string $hash, string $exportId): string
    {
        $p = $pack['policy'];
        $rows = '';
        foreach ($pack['chronology'] as $v) {
            $covs = implode(', ', array_map(fn ($c) => e($c['coverage_code']), $v['coverages']));
            $rows .= '<tr><td>'.$v['version_no'].'</td><td>'.e($v['kind']).'</td><td>'.e((string) $v['valid_from']).'</td><td>'.e((string) $v['valid_to']).'</td><td>'.$covs.'</td></tr>';
        }
        $docs = '';
        foreach ($pack['documents'] as $d) {
            $docs .= '<tr><td>'.e((string) $d['document_number']).'</td><td>'.e((string) $d['type']).'</td><td style="font-size:8px">'.e($d['sha256']).'</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:3px;text-align:left}</style></head><body>'
            .'<h2>Dossier de portabilité / Policy portability pack</h2>'
            .'<p>Police / Policy: <strong>'.e((string) $p['policy_number']).'</strong> — '.e($p['status']).'<br>Export: '.e($exportId).'<br>SHA-256 (JSON pack): '.e($hash).'</p>'
            .'<h3>Chronologie / Chronology</h3><table><tr><th>#</th><th>Kind</th><th>From</th><th>To</th><th>Coverages</th></tr>'.$rows.'</table>'
            .'<h3>Documents</h3><table><tr><th>Number</th><th>Type</th><th>SHA-256</th></tr>'.$docs.'</table>'
            .'<p>Transactions: '.count($pack['transactions']).' · Servicing changes: '.count($pack['servicing_history']).'</p>'
            .'<p style="font-size:8px">The JSON pack is authoritative; this PDF is a summary. Verify it against the SHA-256 above.</p></body></html>';
    }
}
