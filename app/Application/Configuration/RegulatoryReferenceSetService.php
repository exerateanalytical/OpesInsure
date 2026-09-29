<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Versioned regulatory reference sets (configuration/regulatory-reference-sets): DRAFT version, then a checker
 * activates it and retires the previously ACTIVE version of the same code. Used by the API
 * (RegulatoryConfigurationController) and the staff desktop (ReferenceConfigActions); moved out of the controller
 * unchanged so both call one implementation.
 */
final class RegulatoryReferenceSetService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @param array{jurisdiction:string, code:string, effective_from:string, effective_until?:?string, entries:array} $d */
    public function draft(array $d): array
    {
        $version = (DB::table('regulatory_reference_sets')->where(['jurisdiction' => $d['jurisdiction'], 'code' => $d['code']])->max('version') ?? 0) + 1;
        $canonical = json_encode($d['entries'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $id = (string) Str::uuid();
        DB::table('regulatory_reference_sets')->insert([...$d, 'id' => $id, 'version' => $version, 'status' => 'DRAFT', 'entries' => $canonical,
            'content_hash' => hash('sha256', $canonical), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('regulatory_reference.created', 'regulatory_reference_set', $id, ['code' => $d['code'], 'version' => $version]);

        return ['id' => $id, 'version' => $version, 'status' => 'DRAFT'];
    }

    public function approve(string $set, User $checker): array
    {
        $row = DB::table('regulatory_reference_sets')->where('id', $set)->where('status', 'DRAFT')->first();
        abort_unless($row, 409);
        DB::transaction(function () use ($row, $set, $checker) {
            DB::table('regulatory_reference_sets')->where(['jurisdiction' => $row->jurisdiction, 'code' => $row->code, 'status' => 'ACTIVE'])
                ->update(['status' => 'RETIRED', 'effective_until' => now()->toDateString(), 'updated_at' => now()]);
            DB::table('regulatory_reference_sets')->where('id', $set)->update(['status' => 'ACTIVE', 'approved_by' => $checker->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('regulatory_reference.approved', 'regulatory_reference_set', $set, ['code' => $row->code, 'version' => $row->version]);
        });

        return ['id' => $set, 'status' => 'ACTIVE'];
    }
}
