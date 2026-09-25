<?php

declare(strict_types=1);

namespace App\Application\Customers\Matching;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Models\Parties\EntityMatchCandidate;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PTY-004 — probable-duplicate detection for the party golden record. Produces scored, explained candidates
 * (entity_match_candidates) for a data steward; it NEVER merges. Merging is PartyMergeService (maker-checker).
 *
 * Score (0–1, capped): shared identifier hash 0.70 · same registration number 0.60 · name similarity ≤ 0.35 ·
 * same date of birth 0.20. Bands: HIGH ≥ 0.85, MEDIUM ≥ 0.65, LOW ≥ 0.50 (below 0.50 is not recorded).
 */
final class PartyMatcher
{
    public const MIN_SCORE = 0.5;

    private const POOL_LIMIT = 200;

    public function __construct(private readonly AuditWriter $audit, private readonly CaseService $cases) {}

    /**
     * Scans one party against the golden record and upserts its OPEN candidates. A DATA_STEWARD case is opened per
     * new candidate when a tenant is given.
     *
     * @return list<EntityMatchCandidate>
     */
    public function scan(Party $party, ?string $tenantId = null, ?\App\Models\User $actor = null): array
    {
        if ($party->merged_into_id) {
            throw ValidationException::withMessages(['party' => 'A merged party is not scanned.']);
        }
        $out = [];
        foreach ($this->pool($party) as $other) {
            [$score, $reasons] = $this->score($party, $other);
            if ($score < self::MIN_SCORE) {
                continue;
            }
            [$a, $b] = strcmp($party->id, $other->id) < 0 ? [$party->id, $other->id] : [$other->id, $party->id];
            $c = EntityMatchCandidate::where(['entity_type' => 'party', 'party_a_id' => $a, 'party_b_id' => $b])->first();
            if ($c && $c->status !== 'OPEN') {
                $out[] = $c;   // decided pairs (dismissed / merged) are not reopened by a rescan

                continue;
            }
            $c ??= new EntityMatchCandidate(['entity_type' => 'party', 'party_a_id' => $a, 'party_b_id' => $b, 'status' => 'OPEN']);
            $isNew = ! $c->exists;
            $c->fill(['score' => round($score, 4), 'band' => self::band($score), 'reasons' => $reasons])->save();
            if ($isNew) {
                $this->audit->record('party.match_candidate', 'party', $party->id, ['candidate_id' => $c->id, 'other_party_id' => $other->id, 'score' => $c->score]);
                if ($tenantId) {
                    $this->openStewardCase($c, $tenantId, $actor);
                }
            }
            $out[] = $c->refresh();
        }

        return $out;
    }

    public function dismiss(EntityMatchCandidate $c, string $userId, string $note): EntityMatchCandidate
    {
        if ($c->status !== 'OPEN') {
            throw ValidationException::withMessages(['candidate' => "This candidate is {$c->status}."]);
        }
        $c->update(['status' => 'DISMISSED', 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_note' => mb_substr($note, 0, 500)]);
        $this->audit->record('party.match_dismissed', 'party', $c->party_a_id, ['candidate_id' => $c->id, 'other_party_id' => $c->party_b_id], $note);

        return $c->refresh();
    }

    /** @return array{0: float, 1: list<array{rule: string, weight: float, detail?: string}>} */
    public function score(Party $a, Party $b): array
    {
        $reasons = [];
        $shared = DB::table('party_identifiers as x')->join('party_identifiers as y', function ($j) {
            $j->on('x.value_hash', '=', 'y.value_hash')->on('x.type', '=', 'y.type');
        })->where('x.party_id', $a->id)->where('y.party_id', $b->id)->pluck('x.type')->all();
        if ($shared) {
            $reasons[] = ['rule' => 'IDENTIFIER', 'weight' => 0.70, 'detail' => implode(',', array_unique($shared))];
        }
        $regA = self::norm((string) ($a->legal_identity['registration_number'] ?? ''));
        if ($regA !== '' && $regA === self::norm((string) ($b->legal_identity['registration_number'] ?? ''))) {
            $reasons[] = ['rule' => 'REGISTRATION_NUMBER', 'weight' => 0.60];
        }
        $nameSim = self::nameSimilarity($a->display_name, $b->display_name);
        if ($nameSim >= 0.6) {
            $reasons[] = ['rule' => 'NAME', 'weight' => round(0.35 * $nameSim, 4), 'detail' => 'similarity '.round($nameSim, 3)];
        }
        $dob = $a->legal_identity['date_of_birth'] ?? null;
        if ($dob && $dob === ($b->legal_identity['date_of_birth'] ?? null)) {
            $reasons[] = ['rule' => 'DATE_OF_BIRTH', 'weight' => 0.20];
        }

        return [min(1.0, array_sum(array_column($reasons, 'weight'))), $reasons];
    }

    public static function band(float $score): string
    {
        return $score >= 0.85 ? 'HIGH' : ($score >= 0.65 ? 'MEDIUM' : 'LOW');
    }

    /** Token-sorted, accent-free similarity (0–1) so "NGONO Marie" ≈ "Marie Ngono". */
    public static function nameSimilarity(string $x, string $y): float
    {
        $n = function (string $s): string {
            $t = array_filter(preg_split('/\s+/', trim(preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii($s))))) ?: []);
            sort($t);

            return implode(' ', $t);
        };
        [$x, $y] = [$n($x), $n($y)];
        if ($x === '' || $y === '') {
            return 0.0;
        }
        if ($x === $y) {
            return 1.0;
        }
        $max = max(strlen($x), strlen($y));

        return max(0.0, 1 - levenshtein($x, $y) / $max);
    }

    private static function norm(string $s): string
    {
        return mb_strtoupper(preg_replace('/[\s\-\/.]+/', '', $s));
    }

    /** Blocking: same type, live, sharing an identifier, a date of birth / registration number or a name token. */
    private function pool(Party $p)
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', Str::lower(Str::ascii($p->display_name))) ?: [], fn ($t) => mb_strlen($t) >= 3));
        $hashes = DB::table('party_identifiers')->where('party_id', $p->id)->pluck('value_hash')->all();
        $dob = $p->legal_identity['date_of_birth'] ?? null;
        $reg = $p->legal_identity['registration_number'] ?? null;

        return Party::where('type', $p->type)->where('id', '!=', $p->id)->whereNull('merged_into_id')
            ->where(function ($q) use ($tokens, $hashes, $dob, $reg) {
                $q->whereRaw('1 = 0');
                foreach (array_slice($tokens, 0, 4) as $t) {
                    $q->orWhereRaw('lower(display_name) like ?', ['%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%']);
                }
                if ($hashes) {
                    $q->orWhereIn('id', DB::table('party_identifiers')->whereIn('value_hash', $hashes)->select('party_id'));
                }
                if ($dob) {
                    $q->orWhereRaw("legal_identity->>'date_of_birth' = ?", [$dob]);
                }
                if ($reg) {
                    $q->orWhereRaw("legal_identity->>'registration_number' = ?", [$reg]);
                }
            })->limit(self::POOL_LIMIT)->get();
    }

    private function openStewardCase(EntityMatchCandidate $c, string $tenantId, ?\App\Models\User $actor): void
    {
        try {
            $case = $this->cases->open($tenantId, 'DATA_STEWARD', [
                'title' => 'Probable duplicate party ('.self::band((float) $c->score).' '.$c->score.')',
                'subject_type' => 'entity_match_candidate', 'subject_id' => $c->id,
                'source_type' => 'entity_match_candidate', 'source_id' => $c->id, 'idempotency_key' => 'party-match:'.$c->id,
            ], $actor);
            $c->update(['case_id' => $case->id]);
        } catch (\Throwable $e) {
            // The candidate is the durable record; the steward case is a work item. Never lose the candidate over routing.
            Log::warning('party match: steward case not opened', ['candidate' => $c->id, 'error' => $e->getMessage()]);
        }
    }
}
