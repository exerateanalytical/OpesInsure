<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\ClaimsWorkbench;

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use App\Filament\Shared\Actions\ClaimsWorkbenchActions;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Claim;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * CLP-004 assignment details — the adjuster's file on one of their own assignments, one tab per task:
 * overview (004), incident (006), policy & coverage (007), evidence repository (008), inspection (009 / 010 / 011),
 * damage & estimate (012 / 013), report (014), recommendation (016) and history. Actions: ClaimsWorkbenchActions.
 * Someone else's assignment, or a claim of another carrier, is a 404 (ExpertAssignmentService::assertAdjusterOwns).
 */
final class AssignmentWorkbench extends WorkbenchPage
{
    protected static ?string $slug = 'adjuster-workbench/assignment';

    protected static string $screen = 'assignment';

    protected static bool $shouldRegisterNavigation = false;

    #[Url, Locked]
    public ?string $assignment = null;

    private ?object $row = null;

    public function mount(): void
    {
        parent::mount();
        abort_unless(is_string($this->assignment) && \Illuminate\Support\Str::isUuid($this->assignment), 404);
        abort_if($this->assignmentRow() === null, 404);
    }

    public function assignmentRow(): ?object
    {
        $user = auth()->user();
        if ($this->row === null && $this->tenantId !== null && $user instanceof User && is_string($this->assignment)) {
            $this->row = rescue(fn () => app(AdjusterWorkbench::class)->assignment($this->tenantId, $user, $this->assignment),
                fn ($e) => $e instanceof ApiProblemException ? null : throw $e, false);
        }

        return $this->row;
    }

    public function claimRecord(): ?Claim
    {
        return $this->assignmentRow()?->claim;
    }

    /** Actions run against fresh data. */
    public function refreshRow(): void
    {
        $this->row = null;
    }

    public function getTitle(): string
    {
        return self::t('nav.assignment').' · '.($this->claimRecord()?->claim_number ?? '');
    }

    public function getBreadcrumbs(): array
    {
        return [MyAssignments::getUrl() => self::t('nav.assignments'), self::t('nav.assignment')];
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (Action $a) => $a->after(fn () => $this->refreshRow()), ClaimsWorkbenchActions::all());
    }

    public function content(Schema $schema): Schema
    {
        $a = $this->assignmentRow();
        $claim = $this->claimRecord();
        if ($a === null || $claim === null) {
            return $schema->components([]);
        }
        $wb = app(AdjusterWorkbench::class);
        $cur = $claim->currency ?: 'XAF';

        return $schema->components([Tabs::make('workbench')->persistTabInQueryString()->tabs([
            Tab::make(self::t('tabs.overview'))->icon('lucide-hard-hat')->schema([
                Section::make(self::t('sections.assignment'))->columns(['default' => 1, 'md' => 3])->schema([
                    self::entry('claim_number', $claim->claim_number)->copyable(),
                    self::entry('status', self::t('stages.'.$a->status))->badge(),
                    self::entry('provider', $a->provider_name ?? null),
                    self::date('assigned_at', $a->assigned_at),
                    self::date('accepted_at', $a->accepted_at),
                    self::money('fee', $a->fee_amount_minor, $a->fee_currency),
                    self::entry('instructions', $a->instructions)->columnSpanFull(),
                    self::entry('review_notes', $a->review_notes)->columnSpanFull(),
                    self::entry('return_count', $a->return_count),
                    self::entry('sla', collect($a->sla ?? [])->map(fn ($c) => $c->metric.' → '.$c->due_at.($c->breached_at ? ' ⚠' : ''))->implode(' · ') ?: null)->columnSpan(2),
                ]),
                Section::make(self::t('sections.claim'))->columns(['default' => 1, 'md' => 3])->schema([
                    self::badge('claim_status', $claim->status),
                    self::entry('priority', $claim->priority),
                    self::entry('claimant', $claim->claimant?->display_name),
                    self::money('estimated_loss', $claim->estimated_loss_minor, $cur),
                ]),
            ]),
            $this->incidentTab($wb->incident($claim)),
            $this->policyTab($wb->policyCoverage($claim)),
            $this->evidenceTab($wb->evidence($claim)),
            Tab::make(self::t('tabs.inspection'))->icon('lucide-calendar-check')->schema([
                Section::make(self::t('sections.inspection'))->columns(['default' => 1, 'md' => 3])->schema([
                    self::date('inspection_scheduled_for', $a->inspection_scheduled_for),
                    self::entry('location', $a->inspection_location ?? $claim->loss_location),
                    self::date('inspected_at', $a->inspected_at),
                    self::entry('inspection_notes', $a->inspection_notes)->columnSpanFull()->prose(),
                ]),
            ]),
            Tab::make(self::t('tabs.assessment'))->icon('lucide-calculator')->schema([
                Section::make(self::t('sections.valuation'))->description(self::t('valuation_help'))->columns(['default' => 1, 'md' => 3])->schema([
                    self::money('assessed_loss', $a->assessed_loss_minor, $cur),
                    self::money('reserve', $claim->current_reserve_minor, $cur),
                    self::money('estimated_loss', $claim->estimated_loss_minor, $cur),
                ]),
                Section::make(self::t('sections.recommendations'))->schema([self::list('assessments', $wb->assessments($claim), [
                    'assessment_number' => null, 'status' => 'badge', 'recommended_total_minor' => 'money', 'created_at' => 'date', 'heads' => 'wide', 'rationale' => 'wide',
                ], $cur)]),
            ]),
            Tab::make(self::t('tabs.report'))->icon('lucide-file-text')->schema([
                Section::make(self::t('sections.report'))->columns(['default' => 1, 'md' => 3])->schema([
                    self::date('report_submitted_at', $a->report_submitted_at),
                    self::date('reviewed_at', $a->reviewed_at ?? null),
                    self::entry('report_document', $a->report_document_id ? self::t('attached') : null),
                    TextEntry::make('wb_report_summary')->label(self::t('fields.report_summary'))->state($a->report_summary)->placeholder(self::t('no_report'))
                        ->markdown()->columnSpanFull(),
                ]),
            ]),
            Tab::make(self::t('tabs.history'))->icon('lucide-history')->schema([self::list('history', array_map(fn ($h) => (array) $h, $a->history ?? []), [
                'event' => null, 'from_status' => null, 'to_status' => 'badge', 'actor_side' => null, 'occurred_at' => 'date', 'reason' => 'wide',
            ], $cur)]),
        ])]);
    }

    private function incidentTab(array $i): Tab
    {
        $yes = fn ($v) => $v === null ? null : ($v ? __('claims_workbench.yes') : __('claims_workbench.no'));

        return Tab::make(self::t('tabs.incident'))->icon('lucide-siren')->schema([
            Section::make(self::t('sections.incident'))->columns(['default' => 1, 'md' => 3])->schema([
                self::date('loss_occurred_at', $i['loss_occurred_at']),
                self::entry('location', $i['loss_location']),
                self::date('submitted_at', $i['submitted_at']),
                self::entry('incident_type', $i['incident_type']),
                self::entry('police_report_number', $i['police_report_number']),
                self::entry('coordinates', $i['coordinates']),
                self::entry('injuries_reported', $yes($i['injuries_reported'])),
                self::entry('vehicle_drivable', $yes($i['vehicle_drivable'])),
                self::entry('towing_required', $yes($i['towing_required'])),
                self::entry('description', $i['description'])->columnSpanFull(),
            ]),
            Section::make(self::t('sections.parties'))->schema([self::list('parties', $i['parties'], ['role' => 'badge', 'display_name' => null, 'contact_phone' => null], 'XAF')]),
        ]);
    }

    private function policyTab(array $p): Tab
    {
        $yes = $p['in_force_at_loss'] === null ? null : ($p['in_force_at_loss'] ? __('claims_workbench.yes') : __('claims_workbench.no'));

        return Tab::make(self::t('tabs.policy'))->icon('lucide-file-badge')->schema([
            Section::make(self::t('sections.policy'))->columns(['default' => 1, 'md' => 3])->schema([
                self::entry('policy_number', $p['policy_number']),
                self::badge('policy_status', $p['status']),
                self::entry('in_force_at_loss', $yes)->badge()->color($p['in_force_at_loss'] ? 'success' : 'danger'),
                self::date('coverage_starts_at', $p['coverage_starts_at'], false),
                self::date('coverage_ends_at', $p['coverage_ends_at'], false),
            ]),
            Section::make(self::t('sections.covers'))->schema([self::list('covers', $p['covers'], ['code' => null, 'name' => null, 'limit_minor' => 'money', 'deductible_minor' => 'money'], $p['currency'])]),
            Section::make(self::t('sections.coverage_checks'))->schema([self::list('checks', $p['checks'], ['outcome' => 'badge', 'coverage_code' => null, 'checked_at' => 'date', 'resolution' => 'badge', 'resolution_note' => 'wide'], $p['currency'])]),
        ]);
    }

    private function evidenceTab(array $e): Tab
    {
        return Tab::make(self::t('tabs.evidence'))->icon('lucide-folder-open')->badge(count($e['links']) ?: null)->schema([
            Section::make(self::t('sections.evidence'))->description(self::t('evidence_help'))->schema([self::list('evidence', $e['links'], [
                'evidence_type' => null, 'status' => 'badge', 'scan_status' => 'badge', 'mime_type' => null, 'submitted_at' => 'date', 'verified_at' => 'date', 'rejection_reason' => 'wide',
            ], 'XAF')]),
            // S4: files still in the malware scan (or quarantined) — listed, never downloadable.
            Section::make(__('scan_queue.pending.heading'))->icon('lucide-shield-alert')->visible(($e['pending'] ?? []) !== [])->schema([
                RepeatableEntry::make('pending_scan')->hiddenLabel()->state($e['pending'] ?? [])->columns(['default' => 1, 'md' => 3])->schema([
                    TextEntry::make('filename')->label(__('scan_queue.pending.filename')),
                    TextEntry::make('uploaded_at')->label(__('scan_queue.pending.uploaded_at'))->dateTime(),
                    TextEntry::make('status_label')->label(__('scan_queue.pending.status_column'))->badge()
                        ->color(fn ($state) => $state === __('scan_queue.pending.status.INFECTED') ? 'danger' : 'warning'),
                ])->columnSpanFull(),
            ]),
            Section::make(self::t('sections.checklist'))->schema([self::list('checklist', $e['checklist'], ['document_type_id' => null, 'name' => null, 'mandatory' => 'bool', 'status' => 'badge'], 'XAF')]),
        ]);
    }

    /** @param list<array<string,mixed>> $rows @param array<string, ?string> $cols field => kind (null|badge|money|date|wide|bool) */
    private static function list(string $key, array $rows, array $cols, string $currency): RepeatableEntry
    {
        $schema = [];
        foreach ($cols as $f => $kind) {
            $e = TextEntry::make($f)->label(self::t('fields.'.$f))->placeholder('—');
            $e = match ($kind) {
                'badge' => $e->badge()->color(fn ($state) => \App\Filament\Shared\Components\RecordInfolist::color($state)),
                'money' => $e->formatStateUsing(fn ($state) => $state === null ? null : \App\Application\WebExperiences\Money::format((int) $state, $currency)),
                'date' => $e->dateTime(),
                'wide' => $e->columnSpan(2),
                'bool' => $e->formatStateUsing(fn ($state) => $state ? __('claims_workbench.yes') : __('claims_workbench.no')),
                default => $e,
            };
            $schema[] = $e;
        }

        return RepeatableEntry::make('wb_list_'.$key)->hiddenLabel()->state($rows)->placeholder(self::t('empty'))->columns(['default' => 1, 'md' => 4])->schema($schema)->columnSpanFull();
    }
}
