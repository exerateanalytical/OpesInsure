<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Claims;

use App\Application\WebExperiences\Money;
use App\Filament\Shared\Components\RecordInfolist;
use App\Models\Claim;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\DB;

/**
 * Read tabs on the claim detail page giving the context the case-handling actions (ClaimCaseActions) need:
 * investigations, experts, involved parties, coverage checks, evidence review, settlements, carrier exchange and
 * litigation. Each tab lists the claim's rows (read-only); the actions stay in the page header.
 */
final class ClaimCaseTabs
{
    /** @return list<Tab> */
    public static function tabs(): array
    {
        return [
            self::tab('investigations', fn (Claim $c) => DB::table('claim_investigations')->where('claim_id', $c->id)->orderByDesc('created_at'), [
                self::text('reason_code'), self::status('status'), self::text('outcome'), self::date('concluded_at'), self::text('reason', 2), self::text('findings', 2), self::text('outcome_summary', 2),
            ]),
            self::tab('experts', fn (Claim $c) => DB::table('claim_assignments')->leftJoin('provider_profiles', 'provider_profiles.id', '=', 'claim_assignments.provider_profile_id')
                ->leftJoin('parties', 'parties.id', '=', 'provider_profiles.party_id')
                ->where('claim_assignments.claim_id', $c->id)->where('claim_assignments.assignment_type', 'EXPERT')
                ->select('claim_assignments.*', 'parties.display_name as expert_name')->orderByDesc('claim_assignments.assigned_at'), [
                    self::text('expert_name'), self::status('status'), self::money('fee_amount_minor', 'fee_currency'), self::money('assessed_loss_minor', 'fee_currency'),
                    self::date('inspection_scheduled_for'), self::date('report_submitted_at'), self::text('report_summary', 2), self::text('review_notes', 2),
                ]),
            self::tab('parties', fn (Claim $c) => DB::table('claim_involved_parties')->where('claim_id', $c->id)->orderBy('created_at'), [
                self::text('role'), self::text('display_name'), self::text('contact_phone'), self::text('contact_email'), self::text('consent_basis'), self::date('removed_at'), self::text('removal_reason', 2),
            ]),
            self::tab('coverage', fn (Claim $c) => DB::table('claim_coverage_checks')->where('claim_id', $c->id)->orderByDesc('checked_at'), [
                self::status('outcome'), self::text('coverage_code'), self::date('checked_at'), self::status('resolution'), self::text('resolution_note', 2), self::date('resolved_at'),
            ]),
            self::tab('evidence', fn (Claim $c) => DB::table('claim_documents')->leftJoin('documents', 'documents.id', '=', 'claim_documents.document_id')
                ->where('claim_documents.claim_id', $c->id)->select('claim_documents.*', 'documents.category')->orderByDesc('claim_documents.submitted_at'), [
                    self::text('evidence_type'), self::text('category'), self::status('status'), self::date('submitted_at'), self::date('verified_at'), self::text('rejection_reason', 2),
                ], [self::pendingScan()]),
            self::tab('settlements', fn (Claim $c) => DB::table('claim_settlements')->where('claim_id', $c->id)->orderByDesc('created_at'), [
                self::text('reference'), self::status('status'), self::money('amount_minor'), self::money('covered_minor'), self::money('deductible_minor'), self::date('offered_at'), self::date('accepted_at'), self::text('dispute_reason', 2),
            ]),
            self::tab('carrier', fn (Claim $c) => DB::table('carrier_exchange_messages')->where('claim_id', $c->id)->orderByDesc('created_at'), [
                self::text('direction'), self::text('message_type'), self::status('status'), self::text('external_reference'), self::date('created_at'), self::date('acknowledged_at'), self::text('failure_reason', 2),
            ]),
            self::tab('litigation', fn (Claim $c) => DB::table('legal_matters')->where('claim_id', $c->id)->orderByDesc('created_at'), [
                self::text('role'), self::text('court'), self::text('court_reference'), self::text('opposing_party_name'), self::status('status'), self::money('claimed_amount_minor'), self::text('outcome'), self::money('outcome_amount_minor'), self::date('concluded_at'),
            ]),
        ];
    }

    /** S4: evidence still in the malware scan (or quarantined) — listed, never downloadable. */
    private static function pendingScan(): RepeatableEntry
    {
        $rows = fn (?Claim $record) => $record ? rescue(fn () => app(\App\Application\Documents\Scanning\PendingDocuments::class)->forClaim($record->id), [], false) : [];

        return RepeatableEntry::make('case_evidence_pending_scan')->label(__('scan_queue.pending.heading'))
            ->state(fn (?Claim $record) => $rows($record))->visible(fn (?Claim $record) => $rows($record) !== [])
            ->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('filename')->label(__('scan_queue.pending.filename')),
                TextEntry::make('uploaded_at')->label(__('scan_queue.pending.uploaded_at'))->dateTime(),
                TextEntry::make('status_label')->label(__('scan_queue.pending.status_column'))->badge()
                    ->color(fn ($state) => $state === __('scan_queue.pending.status.INFECTED') ? 'danger' : 'warning'),
            ]);
    }

    private static function tab(string $key, \Closure $query, array $entries, array $extra = []): Tab
    {
        $rows = fn (?Claim $record) => $record ? rescue(fn () => $query($record)->limit(100)->get()->map(fn ($r) => (array) $r)->all(), [], false) : [];

        return Tab::make(__('claim_actions.tabs.'.$key))
            ->badge(fn (?Claim $record) => count($rows($record)) ?: null)
            ->schema([
                RepeatableEntry::make('case_'.$key)->hiddenLabel()->state(fn (?Claim $record) => $rows($record))
                    ->placeholder(__('web_experience.claim_work.empty'))->columns(['default' => 1, 'md' => 4])->schema($entries)->columnSpanFull(),
                ...$extra,
            ]);
    }

    private static function text(string $field, int $span = 1): TextEntry
    {
        return TextEntry::make($field)->label(__('claim_actions.columns.'.$field))->placeholder('—')->columnSpan($span);
    }

    private static function status(string $field): TextEntry
    {
        return self::text($field)->badge()->color(fn ($state) => RecordInfolist::color($state));
    }

    private static function date(string $field): TextEntry
    {
        return self::text($field)->dateTime();
    }

    private static function money(string $field, string $currency = 'currency'): TextEntry
    {
        return self::text($field)->formatStateUsing(fn ($state, $component) => $state === null ? null
            : Money::format((int) $state, (string) (data_get($component->getContainer()->getConstantState(), $currency) ?: 'XAF')));
    }
}
