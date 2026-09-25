<?php

declare(strict_types=1);

namespace App\Application\ReferenceDatasets;

use App\Application\Audit\AuditWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner decision (2026-09-25): public holidays and hazard zones are versioned institutional datasets — source,
 * jurisdiction, effective dates, verification status — never hard-coded. Nothing is seeded (OQ-6.2, OQ-7.1).
 *
 * DRAFT (with entries) → ACTIVE by a different user (maker-checker); activating a version retires the previous
 * ACTIVE version of the same kind/jurisdiction/code. Entries are immutable once the version leaves DRAFT.
 * ACTIVE PUBLIC_HOLIDAYS datasets are consumed by the SLA calendar (BusinessHoursCalendar).
 */
final class ReferenceDatasetService
{
    public const KINDS = ['PUBLIC_HOLIDAYS', 'HAZARD_ZONES'];

    public function __construct(private readonly AuditWriter $audit) {}

    /** @param array<string, mixed> $d header + entries */
    public function draft(array $d, User $maker): object
    {
        return DB::transaction(function () use ($d, $maker) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["dataset:{$d['kind']}:{$d['jurisdiction']}:{$d['code']}"]);
            $version = (int) DB::table('reference_datasets')->where(['kind' => $d['kind'], 'jurisdiction' => $d['jurisdiction'], 'code' => $d['code']])->max('version') + 1;
            $entries = array_values($d['entries']);
            $canonical = json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $id = (string) Str::uuid();
            DB::table('reference_datasets')->insert([
                'id' => $id, 'kind' => $d['kind'], 'jurisdiction' => $d['jurisdiction'], 'code' => $d['code'], 'version' => $version,
                'source_name' => $d['source_name'], 'source_reference' => $d['source_reference'] ?? null, 'source_url' => $d['source_url'] ?? null,
                'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'] ?? null,
                'verification_status' => $d['verification_status'] ?? 'UNVERIFIED', 'verification_note' => $d['verification_note'] ?? null,
                'status' => 'DRAFT', 'content_hash' => hash('sha256', $canonical), 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($entries as $e) {
                if ($d['kind'] === 'PUBLIC_HOLIDAYS') {
                    DB::table('public_holiday_entries')->insert([
                        'id' => (string) Str::uuid(), 'dataset_id' => $id, 'date' => $e['date'], 'label' => $e['label'],
                        'holiday_type' => $e['holiday_type'] ?? 'PUBLIC', 'legal_reference' => $e['legal_reference'] ?? null,
                    ]);
                } else {
                    DB::table('hazard_zone_entries')->insert([
                        'id' => (string) Str::uuid(), 'dataset_id' => $id, 'hazard_type' => $e['hazard_type'], 'zone_code' => $e['zone_code'], 'name' => $e['name'],
                        'admin_area_code' => $e['admin_area_code'] ?? null, 'hazard_level' => $e['hazard_level'] ?? null,
                        'geometry' => isset($e['geometry']) ? json_encode($e['geometry']) : null, 'attributes' => json_encode($e['attributes'] ?? (object) []),
                    ]);
                }
            }
            $this->audit->record('reference_dataset.drafted', 'reference_dataset', $id, ['kind' => $d['kind'], 'code' => $d['code'], 'version' => $version, 'entries' => count($entries)]);

            return DB::table('reference_datasets')->find($id);
        });
    }

    public function activate(string $id, User $checker, ?string $verificationStatus, ?string $note): object
    {
        return DB::transaction(function () use ($id, $checker, $verificationStatus, $note) {
            $row = DB::table('reference_datasets')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ($row->status !== 'DRAFT') {
                throw new ApiProblemException('DATASET_NOT_DRAFT', 409, 'Only a DRAFT dataset version can be activated.');
            }
            if ($row->created_by === $checker->id) {
                throw new ApiProblemException('MAKER_CHECKER_REQUIRED', 403, 'The maker of a dataset version cannot activate it.');
            }
            $verification = $verificationStatus ?? $row->verification_status;
            if ($verification === 'VERIFIED' && trim((string) $row->source_reference.$row->source_url) === '') {
                throw new ApiProblemException('SOURCE_REFERENCE_REQUIRED', 422, 'A VERIFIED dataset needs a source reference or URL.');
            }
            $today = now()->toDateString();
            DB::table('reference_datasets')->where(['kind' => $row->kind, 'jurisdiction' => $row->jurisdiction, 'code' => $row->code, 'status' => 'ACTIVE'])
                ->update(['status' => 'RETIRED', 'effective_until' => DB::raw("LEAST(COALESCE(effective_until, DATE '{$today}'), DATE '{$today}')"), 'updated_at' => now()]);
            DB::table('reference_datasets')->where('id', $id)->update([
                'status' => 'ACTIVE', 'verification_status' => $verification, 'verification_note' => $note ?? $row->verification_note,
                'approved_by' => $checker->id, 'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('reference_dataset.activated', 'reference_dataset', $id, ['kind' => $row->kind, 'code' => $row->code, 'version' => $row->version, 'verification_status' => $verification]);

            return DB::table('reference_datasets')->find($id);
        });
    }

    public function retire(string $id, string $reason): object
    {
        if (DB::table('reference_datasets')->where('id', $id)->whereIn('status', ['DRAFT', 'ACTIVE'])->update(['status' => 'RETIRED', 'updated_at' => now()]) !== 1) {
            throw new ApiProblemException('DATASET_NOT_RETIRABLE', 409, 'Dataset not found or already retired.');
        }
        $this->audit->record('reference_dataset.retired', 'reference_dataset', $id, [], $reason);

        return DB::table('reference_datasets')->find($id);
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $row = DB::table('reference_datasets')->find($id) ?? abort(404);
        $table = $row->kind === 'PUBLIC_HOLIDAYS' ? 'public_holiday_entries' : 'hazard_zone_entries';
        $entries = DB::table($table)->where('dataset_id', $id)->orderBy($row->kind === 'PUBLIC_HOLIDAYS' ? 'date' : 'zone_code')->get()
            ->map(function ($e) {
                foreach (['geometry', 'attributes'] as $k) {
                    if (property_exists($e, $k) && is_string($e->{$k})) {
                        $e->{$k} = json_decode($e->{$k}, true);
                    }
                }

                return $e;
            });

        return (array) $row + ['entries' => $entries];
    }
}
