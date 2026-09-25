<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening;

use App\Application\Audit\AuditWriter;
use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListEntry;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListVersion;
use App\Application\Events\OutboxWriter;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agent E8 — REQ-AML-001 / REQ-KYC-004 screening of parties against the tenant's ACTIVE list versions (PEP / sanctions /
 * watchlists). Each run writes a screening_checks row (subject_type "party", provider LIST_MATCH, check_type LIST) and one
 * screening_hits row per entry at or above the match threshold, with the NameMatcher explanation. A hit already raised
 * for the same party + source + entry_ref + unchanged entry content is not raised again (so a FALSE_POSITIVE disposition
 * holds until the entry changes). Dispositions FALSE_POSITIVE / TRUE_MATCH / ESCALATED are maker-checker: a maker
 * proposes, a different user approves or rejects. Wave A contract: E9 (risk / STR) calls this class guarded by class_exists.
 */
final class ScreeningService
{
    public const PROVIDER = 'LIST_MATCH';

    public const CHECK_TYPE = 'LIST';

    /** @var array<string, Collection<int, ScreeningListEntry>> active entries per tenant, cached during one rescreen run */
    private array $entryCache = [];

    public function __construct(private readonly NameMatcher $matcher, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function threshold(string $tenantId): float
    {
        $settings = Tenant::whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : (array) $settings;

        return (float) ($settings['aml']['screening']['match_threshold'] ?? config('aml.screening.match_threshold', 0.85));
    }

    public function hasActiveLists(string $tenantId): bool
    {
        return ScreeningListVersion::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->exists();
    }

    /** @return list<string> names screened for the party: display name plus the names held in legal_identity */
    public function partyNames(Party $party): array
    {
        $li = (array) ($party->legal_identity ?? []);
        $names = [(string) $party->display_name, (string) ($li['full_name'] ?? ''), trim(($li['first_name'] ?? '').' '.($li['middle_name'] ?? '').' '.($li['last_name'] ?? '')),
            (string) ($li['legal_name'] ?? ''), ...array_map('strval', (array) ($li['aliases'] ?? []))];

        return array_values(array_unique(array_filter(array_map('trim', $names), fn ($n) => $this->matcher->normalize($n) !== '')));
    }

    /**
     * Screens one party. Returns null check when the tenant has no active list (nothing is recorded).
     *
     * @return array{check: ?ScreeningCheck, hits: list<ScreeningHit>}
     */
    public function screenParty(string $tenantId, Party $party, string $trigger = 'ONBOARDING', ?User $actor = null, ?string $subjectType = null, ?string $subjectId = null): array
    {
        $entries = $this->entries($tenantId);
        if ($entries->isEmpty()) {
            return ['check' => null, 'hits' => []];
        }
        $threshold = $this->threshold($tenantId);
        $names = $this->partyNames($party);
        $dob = (string) (((array) ($party->legal_identity ?? []))['date_of_birth'] ?? '') ?: null;

        return DB::transaction(function () use ($tenantId, $party, $trigger, $actor, $entries, $threshold, $names, $dob, $subjectType, $subjectId) {
            $round = (int) ScreeningCheck::where('tenant_id', $tenantId)->where('party_id', $party->id)->where('provider', self::PROVIDER)->max('screening_round') + 1;
            $check = ScreeningCheck::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'subject_type' => $subjectType ?? 'party', 'subject_id' => $subjectId ?? $party->id,
                'check_type' => self::CHECK_TYPE, 'provider' => self::PROVIDER, 'status' => 'CLEAR', 'result' => [], 'checked_by' => $actor?->id,
                'checked_at' => now(), 'screening_round' => $round, 'trigger' => $trigger]);
            $hits = [];
            $matched = 0;
            foreach ($entries as $entry) {
                $best = null;
                foreach ($names as $pn) {
                    foreach ([$entry->name, ...(array) $entry->aliases] as $en) {
                        $cmp = $this->matcher->compare($pn, (string) $en);
                        if ($best === null || $cmp['score'] > $best['score']) {
                            $best = $cmp + ['party_name' => $pn, 'matched_name' => (string) $en, 'matched_on' => $en === $entry->name ? 'NAME' : 'ALIAS'];
                        }
                    }
                }
                if ($best === null || $best['score'] < $threshold) {
                    continue;
                }
                $matched++;
                $exists = ScreeningHit::where('tenant_id', $tenantId)->where('party_id', $party->id)->where('source_id', $entry->source_id)
                    ->where('entry_ref', $entry->entry_ref)->where('entry_hash', $entry->entry_hash)->exists();
                if ($exists) {
                    continue;
                }
                $explanation = $best + ['threshold' => $threshold, 'list_type' => $entry->entry_type, 'source_code' => $entry->source_code,
                    'list_version' => (int) $entry->list_version, 'dob_match' => $dob !== null && $entry->date_of_birth !== null ? $entry->date_of_birth->toDateString() === $dob : null];
                $hit = ScreeningHit::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'screening_check_id' => $check->id, 'source_id' => $entry->source_id,
                    'version_id' => $entry->version_id, 'entry_id' => $entry->id, 'entry_ref' => $entry->entry_ref, 'entry_hash' => $entry->entry_hash,
                    'list_type' => $entry->entry_type, 'matched_name' => $best['matched_name'], 'party_name' => $best['party_name'], 'score' => $best['score'],
                    'explanation' => $explanation, 'status' => 'OPEN']);
                $this->outbox->record('aml.screening.hit_raised', 'screening_hit', $hit->id, ['tenant_id' => $tenantId, 'party_id' => $party->id,
                    'list_type' => $hit->list_type, 'score' => $hit->score, 'screening_check_id' => $check->id]);
                $hits[] = $hit;
            }
            $check->update(['status' => $matched > 0 ? 'POSSIBLE_MATCH' : 'CLEAR',
                'list_reference' => mb_substr($entries->map(fn ($e) => $e->source_code.' v'.$e->list_version)->unique()->implode(', '), 0, 255),
                'result' => ['threshold' => $threshold, 'names_screened' => $names, 'entries_screened' => $entries->count(), 'matches' => $matched, 'new_hits' => count($hits)]]);
            $this->audit->record('aml.screening.party_screened', 'party', $party->id, ['tenant_id' => $tenantId, 'check_id' => $check->id, 'trigger' => $trigger,
                'matches' => $matched, 'new_hits' => count($hits)]);

            return ['check' => $check->fresh(), 'hits' => $hits];
        });
    }

    /** Rescreens every customer of the tenant (list activation or periodic). Returns the number of parties screened. */
    public function rescreenTenant(string $tenantId, string $trigger, ?User $actor = null): int
    {
        if (! $this->hasActiveLists($tenantId)) {
            return 0;
        }
        $this->entryCache = [];
        $n = 0;
        DB::table('tenant_customers')->where('tenant_id', $tenantId)->orderBy('id')->select('party_id')->chunk(500, function ($rows) use ($tenantId, $trigger, $actor, &$n) {
            foreach (Party::whereIn('id', $rows->pluck('party_id'))->get() as $party) {
                $this->screenParty($tenantId, $party, $trigger, $actor);
                $n++;
            }
        });
        $this->entryCache = [];

        return $n;
    }

    /** Periodic rescreen (aml:rescreen): tenant customers whose last list screening is older than the configured interval. */
    public function rescreenDue(?Carbon $now = null): int
    {
        $now ??= now();
        $n = 0;
        foreach (ScreeningListVersion::where('status', 'ACTIVE')->distinct()->pluck('tenant_id') as $tenantId) {
            $days = $this->intervalDays($tenantId);
            if ($days === null) {
                continue;
            }
            $cutoff = $now->copy()->subDays($days);
            $this->entryCache = [];
            $partyIds = DB::table('tenant_customers as tc')->where('tc.tenant_id', $tenantId)
                ->whereNotExists(fn ($q) => $q->from('screening_checks as sc')->whereColumn('sc.party_id', 'tc.party_id')->where('sc.tenant_id', $tenantId)
                    ->where('sc.provider', self::PROVIDER)->where('sc.checked_at', '>', $cutoff))
                ->pluck('tc.party_id');
            foreach (Party::whereIn('id', $partyIds)->get() as $party) {
                $this->screenParty($tenantId, $party, 'PERIODIC_RESCREEN');
                $n++;
            }
        }
        $this->entryCache = [];

        return $n;
    }

    public function propose(ScreeningHit $hit, string $disposition, string $rationale, User $maker): ScreeningHit
    {
        $disposition = strtoupper($disposition);
        if (! in_array($disposition, ScreeningHit::DISPOSITIONS, true)) {
            throw new ApiProblemException('SCREENING_DISPOSITION_INVALID', 422, 'Disposition must be FALSE_POSITIVE, TRUE_MATCH or ESCALATED.', ['disposition' => ['Invalid disposition.']]);
        }
        if (! ($hit->status === 'OPEN' || ($hit->status === 'DISPOSED' && $hit->disposition === 'ESCALATED'))) {
            throw new ApiProblemException('SCREENING_HIT_NOT_OPEN', 409, 'A disposition can be proposed on an open or escalated hit only.');
        }
        $hit->update(['status' => 'PROPOSED', 'proposed_disposition' => $disposition, 'proposed_rationale' => $rationale, 'proposed_by' => $maker->id, 'proposed_at' => now()]);
        $this->audit->record('aml.screening.hit_disposition_proposed', 'screening_hit', $hit->id, ['party_id' => $hit->party_id, 'disposition' => $disposition], $rationale);
        $this->outbox->record('aml.screening.hit_disposition_proposed', 'screening_hit', $hit->id, ['tenant_id' => $hit->tenant_id, 'party_id' => $hit->party_id, 'disposition' => $disposition]);

        return $hit->fresh();
    }

    public function decide(ScreeningHit $hit, bool $approve, ?string $note, User $checker): ScreeningHit
    {
        if ($hit->status !== 'PROPOSED') {
            throw new ApiProblemException('SCREENING_HIT_NOT_PROPOSED', 409, 'No disposition is awaiting approval on this hit.');
        }
        if ($hit->proposed_by === $checker->id) {
            throw new ApiProblemException('MAKER_CHECKER_VIOLATION', 403, 'The user who proposed a disposition cannot approve it.');
        }
        $proposed = $hit->proposed_disposition;
        $hit->update($approve
            ? ['status' => 'DISPOSED', 'disposition' => $proposed, 'disposition_note' => $note, 'disposed_by' => $checker->id, 'disposed_at' => now()]
            : ['status' => 'OPEN', 'disposition' => null, 'disposition_note' => $note, 'disposed_by' => $checker->id, 'disposed_at' => now(),
                'proposed_disposition' => null, 'proposed_rationale' => null, 'proposed_by' => null, 'proposed_at' => null]);
        $this->audit->record('aml.screening.hit_disposed', 'screening_hit', $hit->id, ['party_id' => $hit->party_id, 'approved' => $approve, 'disposition' => $approve ? $proposed : null,
            'proposed_disposition' => $proposed], $note);
        $this->outbox->record('aml.screening.hit_disposed', 'screening_hit', $hit->id, ['tenant_id' => $hit->tenant_id, 'party_id' => $hit->party_id,
            'approved' => $approve, 'disposition' => $approve ? $proposed : null]);

        return $hit->fresh();
    }

    /** @param list<string> $partyIds  @return Collection<int, ScreeningHit> hits that block bind / issue / payout */
    public function blockingHits(string $tenantId, array $partyIds): Collection
    {
        $partyIds = array_values(array_filter($partyIds));
        if ($partyIds === []) {
            return collect();
        }

        return ScreeningHit::where('tenant_id', $tenantId)->whereIn('party_id', $partyIds)
            ->where(fn ($q) => $q->where('status', '!=', 'DISPOSED')->orWhereIn('disposition', ['TRUE_MATCH', 'ESCALATED']))
            ->orderBy('created_at')->get();
    }

    private function intervalDays(string $tenantId): ?int
    {
        $settings = Tenant::whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : (array) $settings;
        $days = $settings['aml']['screening']['rescreen_interval_days'] ?? config('aml.screening.rescreen_interval_days');

        return $days === null ? null : max(1, (int) $days);
    }

    /** @return Collection<int, ScreeningListEntry> */
    private function entries(string $tenantId): Collection
    {
        return $this->entryCache[$tenantId] ??= ScreeningListEntry::query()
            ->join('screening_list_versions as v', 'v.id', '=', 'screening_list_entries.version_id')
            ->join('screening_list_sources as s', 's.id', '=', 'v.source_id')
            ->where('screening_list_entries.tenant_id', $tenantId)->where('v.status', 'ACTIVE')->where('s.status', 'ACTIVE')
            ->select('screening_list_entries.*', 'v.source_id', 'v.version as list_version', 's.code as source_code')
            ->get();
    }

    /** Test / long-running process helper: forget cached entries. */
    public function flush(): void
    {
        $this->entryCache = [];
    }
}
