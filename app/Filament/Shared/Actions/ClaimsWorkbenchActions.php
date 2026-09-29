<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\Adjusters\ExpertAssignmentLifecycle;
use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Application\Claims\Assessment\ClaimAssessmentService;
use App\Application\Claims\ClaimEvidenceService;
use App\Application\Claims\ClaimReferenceCodes;
use App\Application\Documents\MobileDocumentService;
use App\Application\Documents\Scanning\DocumentScanQueue;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Document;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Q9 claims professional / adjuster workbench actions (AssignmentWorkbench page). The record is the assignment's
 * CLAIM (so WorkflowAction applies PortalScope::isOwnRecord = own carrier), the assignment id comes from the page and
 * every call first runs ExpertAssignmentService::assertAdjusterOwns — the same gate as AdjusterAssignmentController.
 *   wbAccept / wbDecline      POST adjuster/assignments/{a}/accept|decline   claims.experts.work      ExpertAssignmentService::accept|decline
 *   wbSchedule (CLP-009)      POST adjuster/assignments/{a}/inspection       claims.experts.work      ExpertAssignmentService::scheduleInspection
 *   wbFieldCapture (CLP-011)  POST adjuster/assignments/{a}/inspected        claims.experts.work      ExpertAssignmentService::recordInspection (+ photos below)
 *   wbUploadEvidence (008)    POST claims/{id}/evidence                      claims.evidence.manage   MobileDocumentService::upload (scan pipeline) + ClaimEvidenceService::attach
 *   wbReport (CLP-012/13/14)  POST adjuster/assignments/{a}/report           claims.experts.work      ExpertAssignmentService::submitReport
 *   wbRecommend (CLP-016)     POST claims/{claim}/assessments                claims.assessment.record ClaimAssessmentService::record (a recommendation, never the decision)
 * Uploaded files always go through the document scan pipeline: a CLEAN document is linked at once; one still in its
 * security check is recorded as a pending attachment (DocumentScanQueue::deferAttachment) and linked automatically
 * once it scans CLEAN; an INFECTED one is refused.
 */
final class ClaimsWorkbenchActions
{
    private const L = 'claims_workbench';

    private const WORK = 'claims.experts.work';

    private const EVIDENCE = 'claims.evidence.manage';

    private const ASSESS = 'claims.assessment.record';

    private const MIME = ['application/pdf', 'image/jpeg', 'image/png'];

    /** @return list<Action> */
    public static function all(): array
    {
        return [self::accept(), self::decline(), self::schedule(), self::fieldCapture(), self::uploadEvidence(), self::report(), self::recommend()];
    }

    public static function accept(): Action
    {
        return self::lifecycle('wbAccept', 'accept', 'lucide-check')->requiresConfirmation()
            ->action(fn (Action $action, Component $livewire) => self::work($action, $livewire, fn (ExpertAssignmentService $s, string $t, string $id) => $s->accept($t, $id, self::user())));
    }

    public static function decline(): Action
    {
        return self::lifecycle('wbDecline', 'decline', 'lucide-x')->color('danger')
            ->schema([Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(5000)])
            ->action(fn (Action $action, Component $livewire, array $data) => self::work($action, $livewire,
                fn (ExpertAssignmentService $s, string $t, string $id) => $s->decline($t, $id, $data['reason'], self::user())));
    }

    /** CLP-009 inspection scheduling (also reschedules an already scheduled inspection). */
    public static function schedule(): Action
    {
        return self::lifecycle('wbSchedule', 'schedule_inspection', 'lucide-calendar-plus')
            ->schema([
                DateTimePicker::make('scheduled_for')->label(self::f('scheduled_for'))->required()->after('now'),
                TextInput::make('location')->label(self::f('location'))->maxLength(255)
                    ->default(fn (Component $livewire) => $livewire->assignmentRow()?->inspection_location ?? $livewire->claimRecord()?->loss_location),
            ])
            ->action(fn (Action $action, Component $livewire, array $data) => self::work($action, $livewire,
                fn (ExpertAssignmentService $s, string $t, string $id) => $s->scheduleInspection($t, $id, Carbon::parse($data['scheduled_for'])->toIso8601String(), $data['location'] ?? null, self::user())));
    }

    /** CLP-011 field inspection capture: structured observations recorded as the inspection, photos into the evidence repository. */
    public static function fieldCapture(): Action
    {
        return self::lifecycle('wbFieldCapture', 'record_inspection', 'lucide-clipboard-check')
            ->schema([
                DateTimePicker::make('inspected_at')->label(self::f('inspected_at'))->required()->beforeOrEqual('now')->default(now()),
                TextInput::make('attendees')->label(self::f('attendees'))->maxLength(500),
                Select::make('condition')->label(self::f('condition'))->options(self::codes('conditions', ['REPAIRABLE', 'TOTAL_LOSS', 'NO_DAMAGE_FOUND', 'NOT_INSPECTABLE']))->required(),
                Textarea::make('observations')->label(self::f('observations'))->required()->minLength(10)->maxLength(8000)->rows(5),
                FileUpload::make('photos')->label(self::f('photos'))->multiple()->maxFiles(10)->disk('local')->directory('claims-workbench-tmp')
                    ->acceptedFileTypes(self::MIME)->maxSize(20480)
                    ->visible(fn () => WorkflowAction::allowed(self::EVIDENCE)),
            ])
            ->action(function (Action $action, Component $livewire, array $data) {
                $notes = implode("\n", array_filter([
                    self::f('condition').': '.(self::codes('conditions', [$data['condition']])[$data['condition']] ?? $data['condition']),
                    filled($data['attendees'] ?? null) ? self::f('attendees').': '.$data['attendees'] : null,
                    self::f('observations').': '.$data['observations'],
                ]));
                $result = self::work($action, $livewire, fn (ExpertAssignmentService $s, string $t, string $id) => $s->recordInspection($t, $id, $notes,
                    Carbon::parse($data['inspected_at'])->toIso8601String(), self::user()));
                $photos = array_values(array_filter((array) ($data['photos'] ?? [])));
                if ($photos !== [] && WorkflowAction::allowed(self::EVIDENCE, $livewire->claimRecord())) {
                    self::storeEvidence($livewire->claimRecord(), $photos, 'INSPECTION_PHOTO');
                }

                return $result;
            });
    }

    /** CLP-008: add a file to the claim's evidence repository through the scan pipeline. */
    public static function uploadEvidence(): Action
    {
        return self::make('wbUploadEvidence', self::EVIDENCE, 'lucide-upload')
            ->visible(fn (Component $livewire) => ! in_array($livewire->assignmentRow()?->status, ExpertAssignmentLifecycle::TERMINAL, true))
            ->schema([
                Select::make('evidence_type')->label(self::f('evidence_type'))->required()
                    ->options(self::codes('evidence_types', ['DAMAGE_PHOTO', 'INSPECTION_PHOTO', 'REPAIR_QUOTE', 'INVOICE', 'POLICE_REPORT', 'EXPERT_REPORT', 'OTHER'])),
                FileUpload::make('files')->label(self::f('files'))->multiple()->required()->maxFiles(10)->disk('local')->directory('claims-workbench-tmp')
                    ->acceptedFileTypes(self::MIME)->maxSize(20480),
            ])
            ->action(fn (Action $action, Component $livewire, array $data) => WorkflowAction::run($action, self::EVIDENCE, function () use ($livewire, $data) {
                self::owned($livewire);

                return self::storeEvidence($livewire->claimRecord(), array_values((array) $data['files']), $data['evidence_type']);
            }, __(self::L.'.actions.wbUploadEvidence.done')));
    }

    /** CLP-012 damage assessment + CLP-013 estimate / valuation + CLP-014 report builder, submitted as the expert report. */
    public static function report(): Action
    {
        return self::lifecycle('wbReport', 'submit_report', 'lucide-file-text')->modalWidth('5xl')
            ->schema([
                Textarea::make('circumstances')->label(self::f('circumstances'))->required()->minLength(10)->maxLength(5000)->rows(3),
                Textarea::make('cause')->label(self::f('cause'))->required()->minLength(5)->maxLength(3000)->rows(2),
                Repeater::make('damage_items')->label(self::f('damage_items'))->required()->minItems(1)->maxItems(50)->defaultItems(1)->columns(4)->schema([
                    TextInput::make('item')->label(self::f('item'))->required()->maxLength(200),
                    Select::make('severity')->label(self::f('severity'))->options(self::codes('severities', ['MINOR', 'MODERATE', 'SEVERE', 'TOTAL_LOSS']))->required(),
                    Select::make('treatment')->label(self::f('treatment'))->options(self::codes('treatments', ['REPAIR', 'REPLACE', 'NO_ACTION']))->required(),
                    TextInput::make('estimate_minor')->label(self::f('estimate_minor'))->required()->integer()->minValue(0),
                ]),
                TextInput::make('pre_loss_value_minor')->label(self::f('pre_loss_value_minor'))->integer()->minValue(0),
                TextInput::make('salvage_minor')->label(self::f('salvage_minor'))->integer()->minValue(0),
                Textarea::make('conclusion')->label(self::f('conclusion'))->required()->minLength(10)->maxLength(5000)->rows(3),
                FileUpload::make('report_file')->label(self::f('report_file'))->disk('local')->directory('claims-workbench-tmp')->acceptedFileTypes(['application/pdf'])->maxSize(20480)
                    ->visible(fn () => WorkflowAction::allowed(self::EVIDENCE)),
            ])
            ->action(function (Action $action, Component $livewire, array $data) {
                [$summary, $assessed] = self::composeReport($data + ['currency' => $livewire->claimRecord()?->currency]);
                $documentId = null;
                if (filled($data['report_file'] ?? null) && WorkflowAction::allowed(self::EVIDENCE, $livewire->claimRecord())) {
                    $documentId = self::storeEvidence($livewire->claimRecord(), [is_array($data['report_file']) ? reset($data['report_file']) : $data['report_file']], 'EXPERT_REPORT', true)[0] ?? null;
                }

                return self::work($action, $livewire, fn (ExpertAssignmentService $s, string $t, string $id) => $s->submitReport($t, $id,
                    ['summary' => $summary, 'assessed_loss_minor' => $assessed, 'document_id' => $documentId], self::user()));
            });
    }

    /** CLP-016: the adjuster's recommendation per head (ClaimAssessmentService; four-eyes review stays with the insurer). */
    public static function recommend(): Action
    {
        return self::make('wbRecommend', self::ASSESS, 'lucide-send')->modalWidth('4xl')
            ->visible(fn (Component $livewire) => in_array($livewire->assignmentRow()?->status, ['INSPECTED', 'REPORT_SUBMITTED', 'REPORT_RETURNED', 'REPORT_ACCEPTED'], true)
                && ! in_array($livewire->claimRecord()?->status, ClaimAssessmentService::CLOSED_STATUSES, true))
            ->schema([
                Repeater::make('heads')->label(self::f('heads'))->required()->minItems(1)->maxItems(20)->defaultItems(1)->columns(4)->schema([
                    Select::make('head_code')->label(self::f('head'))->options(WorkflowAction::options(ClaimReferenceCodes::RESERVE_TYPES))->required()->default('REPAIR'),
                    TextInput::make('claimed_minor')->label(self::f('claimed_minor'))->integer()->minValue(0),
                    TextInput::make('recommended_minor')->label(self::f('recommended_minor'))->integer()->minValue(0)->required()
                        ->default(fn (Component $livewire) => $livewire->assignmentRow()?->assessed_loss_minor),
                    TextInput::make('note')->label(self::f('note'))->maxLength(1000),
                ]),
                Textarea::make('rationale')->label(self::f('rationale'))->required()->minLength(10)->maxLength(10000)->rows(4),
            ])
            ->action(fn (Action $action, Component $livewire, array $data) => WorkflowAction::run($action, self::ASSESS, function () use ($livewire, $data) {
                $a = self::owned($livewire);

                return app(ClaimAssessmentService::class)->record($livewire->claimRecord(), [
                    'heads' => array_map(fn ($h) => ['head_code' => $h['head_code'], 'recommended_minor' => (int) $h['recommended_minor'],
                        'claimed_minor' => filled($h['claimed_minor'] ?? null) ? (int) $h['claimed_minor'] : null, 'note' => $h['note'] ?? null], array_values($data['heads'])),
                    'rationale' => $data['rationale'],
                    'adjuster_report_document_id' => $a->report_document_id ?? null,
                ], self::user());
            }, __(self::L.'.actions.wbRecommend.done')));
    }

    /** @return array{0: string, 1: int} report text and the assessed loss (sum of the estimates less salvage, never below 0) */
    public static function composeReport(array $d): array
    {
        $items = array_values((array) ($d['damage_items'] ?? []));
        $total = array_sum(array_map(fn ($i) => (int) ($i['estimate_minor'] ?? 0), $items));
        $salvage = (int) ($d['salvage_minor'] ?? 0);
        $assessed = max(0, $total - $salvage);
        $money = fn (int $m) => \App\Application\WebExperiences\Money::format($m, $d['currency'] ?? null);
        $lines = [
            '# '.self::f('circumstances'), trim((string) $d['circumstances']), '',
            '# '.self::f('cause'), trim((string) $d['cause']), '',
            '# '.self::f('damage_items'),
        ];
        foreach ($items as $n => $i) {
            $lines[] = ($n + 1).'. '.$i['item'].' — '.(self::codes('severities', [$i['severity']])[$i['severity']] ?? $i['severity']).' · '
                .(self::codes('treatments', [$i['treatment']])[$i['treatment']] ?? $i['treatment']).' · '.$money((int) $i['estimate_minor']);
        }
        $lines[] = '';
        $lines[] = '# '.__(self::L.'.sections.valuation');
        $lines[] = self::f('estimate_total').': '.$money($total);
        if (filled($d['pre_loss_value_minor'] ?? null)) {
            $lines[] = self::f('pre_loss_value_minor').': '.$money((int) $d['pre_loss_value_minor']);
        }
        if ($salvage > 0) {
            $lines[] = self::f('salvage_minor').': '.$money($salvage);
        }
        $lines[] = self::f('assessed_loss').': '.$money($assessed);
        $lines[] = '';
        $lines[] = '# '.self::f('conclusion');
        $lines[] = trim((string) $d['conclusion']);

        return [implode("\n", $lines), $assessed];
    }

    /**
     * Stores files through the document scan pipeline (MobileDocumentService::upload) and links each CLEAN one as claim
     * evidence. Returns the document ids created. With $any the id is returned whatever the scan result.
     *
     * @param  list<string>  $paths  local-disk temporary paths from FileUpload
     * @return list<string>
     */
    public static function storeEvidence(Claim $claim, array $paths, string $type, bool $any = false): array
    {
        $disk = Storage::disk('local');
        $ids = [];
        $pending = 0;
        foreach ($paths as $path) {
            try {
                $bytes = (string) $disk->get($path);
                $mime = (string) $disk->mimeType($path);
                $doc = app(MobileDocumentService::class)->upload(['category' => 'CLAIM_EVIDENCE', 'mime_type' => $mime, 'file_base64' => base64_encode($bytes)], self::user(), $claim->tenant_id);
            } finally {
                $disk->delete($path);
            }
            $document = Document::where('tenant_id', $claim->tenant_id)->findOrFail($doc['id']);
            if ($document->scan_status === DocumentScanQueue::CLEAN) {
                app(ClaimEvidenceService::class)->attach($claim, $document, $type, 'CLAIM_ASSESSMENT', self::user());
                $ids[] = (string) $document->id;
            } elseif (DocumentScanQueue::isHeld($document->scan_status) || $document->scan_status === DocumentScanQueue::LEGACY_FAILED) {
                // Q1: same mechanism as MobileClaimEvidenceService — the attachment is remembered and performed
                // automatically once the file scans CLEAN.
                app(DocumentScanQueue::class)->deferAttachment($document, DocumentScanQueue::TARGET_CLAIM_EVIDENCE, $claim->id,
                    ['evidence_type' => $type, 'purpose' => 'CLAIM_ASSESSMENT'], self::user());
                $pending++;
                if ($any) {
                    $ids[] = (string) $document->id;
                }
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages(['files' => __(self::L.'.evidence_infected')]);
            }
        }
        if ($pending > 0) {
            \Filament\Notifications\Notification::make()->warning()->title(__('scan_queue.client.in_progress'))
                ->body(__(self::L.'.evidence_pending', ['count' => $pending]))->send();
        }

        return $ids;
    }

    // ------------------------------------------------------------------ internals

    private static function make(string $name, string $permission, string $icon): Action
    {
        return WorkflowAction::make($name, $permission, self::L.'.actions')->icon($icon)
            ->record(fn (Component $livewire) => $livewire->claimRecord());
    }

    private static function lifecycle(string $name, string $event, string $icon): Action
    {
        return self::make($name, self::WORK, $icon)
            ->visible(fn (Component $livewire) => ExpertAssignmentLifecycle::target((string) ($livewire->assignmentRow()?->status ?? ''), $event) !== null);
    }

    /** WorkflowAction::run (permission + own carrier on the claim), after the adjuster-owns check; refreshes the page. */
    private static function work(Action $action, Component $livewire, \Closure $call): mixed
    {
        return WorkflowAction::run($action, self::WORK, function () use ($livewire, $call) {
            $a = self::owned($livewire);

            return $call(app(ExpertAssignmentService::class), (string) $a->tenant_id, (string) $a->id);
        }, __(self::L.'.actions.'.$action->getName().'.done'));
    }

    private static function owned(Component $livewire): object
    {
        $tenant = app(TenantContext::class)->id();
        $a = app(ExpertAssignmentService::class)->find($tenant, (string) $livewire->assignment);
        app(ExpertAssignmentService::class)->assertAdjusterOwns($tenant, self::user(), (string) $a->id);

        return $a;
    }

    private static function user(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    private static function f(string $key): string
    {
        return __(self::L.'.fields.'.$key);
    }

    /** @param list<string> $codes @return array<string,string> */
    private static function codes(string $group, array $codes): array
    {
        return collect($codes)->mapWithKeys(fn ($c) => [$c => WorkflowAction::optional(self::L.'.codes.'.$group.'.'.$c) ?? $c])->all();
    }
}
