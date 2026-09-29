<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Models\KycSubmission;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * BRK-021 KYC Case Details: GET kyc/submissions/{s} for a submission of the caller's book (404 otherwise), its
 * evidence documents, and the KYC actions its status and the caller's permissions allow (KycActions).
 */
final class KycCasePage extends KycScreen
{
    protected static ?string $slug = 'kyc/case';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $screen = 'kyc_case';

    #[Url, Locked]
    public ?string $submission = null;

    public function mount(): void
    {
        parent::mount();
        $this->record();
    }

    private function record(): KycSubmission
    {
        $s = $this->submission && Str::isUuid($this->submission) ? self::submissions($this->tenantId)->whereKey($this->submission)->first() : null;
        abort_unless($s instanceof KycSubmission, 404);

        return $s;
    }

    public function getSubheading(): ?string
    {
        return $this->record()->party?->display_name;
    }

    public function getDetailSections(): array
    {
        $s = $this->record();
        $f = fn (string $k) => __('broker_screens_a.columns.'.$k);
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i') : null;

        return [['heading' => __('broker_screens_a.kyc_case.summary'), 'rows' => [
            $f('status') => self::code('kyc_status', $s->status), $f('subject_kind') => self::code('subject_kind', $s->subject_kind),
            $f('kyc_level') => self::code('kyc_level', $s->kyc_level), $f('screening') => self::code('status', $s->screening_status),
            $f('recommended_outcome') => self::code('status', $s->recommended_outcome), $f('submitted_at') => $d($s->submitted_at),
            $f('approved_at') => $d($s->approved_at), $f('expires_at') => $d($s->expires_at),
            $f('decision_reason') => $s->decision_reason, $f('remediation_reason') => $s->remediation_reason, $f('notes') => $s->notes,
        ]]];
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn ($a) => $a->record(fn () => $this->record()), self::caseActions());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('broker_screens_a.kyc_case.documents'))
            ->records(fn (): array => self::keyed(DB::table('kyc_submission_documents')->join('documents', 'documents.id', '=', 'kyc_submission_documents.document_id')
                ->where('kyc_submission_documents.kyc_submission_id', $this->record()->getKey())->where('documents.tenant_id', $this->tenant())
                ->get(['documents.id', 'documents.title', 'documents.document_type_code', 'kyc_submission_documents.purpose', 'documents.created_at'])))
            ->columns([self::col('title', 'document'), self::col('document_type_code', 'document_type'), self::col('purpose'), self::col('created_at')->date()])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
