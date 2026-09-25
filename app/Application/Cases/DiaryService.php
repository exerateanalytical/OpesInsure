<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Cases\Models\DiaryEntry;
use App\Application\Cases\Models\WorkCase;
use App\Domain\Shared\Clock\Clock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CAS-001 DiaryService (ICE §6.3): append-only notes and follow-ups.
 * An "edit" is a new entry with supersedes_entry_id; follow-ups surface in My Work
 * and emit diary.follow_up_due from the SLA tick.
 */
final class DiaryService
{
    public function __construct(private readonly CaseJournal $journal, private readonly Clock $clock) {}

    /** @param array{entry_type: string, body: string, follow_up_at?: ?string, visibility?: string, supersedes_entry_id?: ?string} $d */
    public function add(WorkCase $case, array $d, User $author): DiaryEntry
    {
        return DB::transaction(function () use ($case, $d, $author) {
            $case = WorkCase::withoutGlobalScopes()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! empty($d['supersedes_entry_id']) && ! DiaryEntry::where('case_id', $case->id)->whereKey($d['supersedes_entry_id'])->exists()) {
                throw CaseProblem::make('DIARY_ENTRY_NOT_FOUND', 422, 'The superseded entry does not belong to this case.');
            }
            $entry = DiaryEntry::create([
                'tenant_id' => $case->tenant_id, 'case_id' => $case->id, 'subject_type' => 'case', 'subject_id' => $case->id,
                'author_id' => $author->id, 'entry_type' => $d['entry_type'], 'body' => $d['body'],
                'follow_up_at' => $d['follow_up_at'] ?? null, 'visibility' => $d['visibility'] ?? 'INTERNAL',
                'supersedes_entry_id' => $d['supersedes_entry_id'] ?? null, 'created_at' => $this->clock->now(),
            ]);
            $this->journal->event($case, 'DIARY_ADDED', ['entry_id' => $entry->id, 'entry_type' => $entry->entry_type, 'follow_up_at' => $entry->follow_up_at?->toIso8601String()], null, null, $author->id);

            return $entry;
        });
    }
}
