<?php

declare(strict_types=1);

namespace App\Application\Authority;

use App\Application\Audit\AuditWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AUTH-001 — owner decision #12: authority types are an extensible catalogue (authority_types), seeded with the
 * blueprint's 14 types, the owner's additions (ENDORSE, BACKDATE, CANCEL, OVERRIDE, RESERVE_APPROVE, CLAIM_SETTLE,
 * REFUND_APPROVE, REINSTATE, PAYMENT_OVERRIDE, JOURNAL_APPROVE, FACULTATIVE_APPROVE) and the legacy codes already in
 * authority_limits. authority_limits.authority_type is a foreign key to it. Types are retired, never deleted.
 */
final class AuthorityTypeCatalogue
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @return Collection<int, object> */
    public function all(bool $activeOnly = false): Collection
    {
        return DB::table('authority_types')->when($activeOnly, fn ($q) => $q->where('status', 'ACTIVE'))->orderBy('source')->orderBy('code')->get();
    }

    public function isActive(string $code): bool
    {
        return DB::table('authority_types')->where('code', $code)->where('status', 'ACTIVE')->exists();
    }

    /** Guard for any writer of authority_limits / authority checks. */
    public function assertActive(string $code): void
    {
        if (! $this->isActive($code)) {
            throw new ApiProblemException('AUTHORITY_TYPE_UNKNOWN', 422, "Authority type {$code} is not an active catalogue entry.", [], ['authority_type' => $code]);
        }
    }

    /** @param array{code: string, name: string, description?: ?string, monetary?: bool} $d */
    public function add(array $d, User $actor): object
    {
        if (DB::table('authority_types')->where('code', $d['code'])->exists()) {
            throw new ApiProblemException('AUTHORITY_TYPE_EXISTS', 409, "Authority type {$d['code']} already exists.");
        }
        DB::table('authority_types')->insert([
            'code' => $d['code'], 'name' => $d['name'], 'description' => $d['description'] ?? null, 'source' => 'ADMIN',
            'monetary' => (bool) ($d['monetary'] ?? true), 'status' => 'ACTIVE', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('authority_type.added', 'authority_type', null, ['code' => $d['code'], 'name' => $d['name']]);

        return DB::table('authority_types')->where('code', $d['code'])->first();
    }

    public function retire(string $code, string $reason): object
    {
        $inUse = DB::table('authority_limits')->where('authority_type', $code)->where('status', 'ACTIVE')->count();
        if ($inUse > 0) {
            throw new ApiProblemException('AUTHORITY_TYPE_IN_USE', 409, "Authority type {$code} has {$inUse} active limit(s); end them first.", [], ['active_limits' => $inUse]);
        }
        if (DB::table('authority_types')->where('code', $code)->where('status', 'ACTIVE')->update(['status' => 'RETIRED', 'updated_at' => now()]) !== 1) {
            throw new ApiProblemException('AUTHORITY_TYPE_NOT_ACTIVE', 409, "Authority type {$code} is not active.");
        }
        $this->audit->record('authority_type.retired', 'authority_type', null, ['code' => $code], $reason);

        return DB::table('authority_types')->where('code', $code)->first();
    }
}
