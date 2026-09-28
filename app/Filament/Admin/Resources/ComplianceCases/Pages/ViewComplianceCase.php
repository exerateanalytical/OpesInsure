<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ComplianceCases\Pages;

use App\Application\Compliance\Cases\ComplianceCaseService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\ComplianceCases\ComplianceCaseResource;
use App\Filament\Shared\Actions\ComplianceActions;
use App\Filament\Shared\Components\RecordInfolist;
use App\Filament\Shared\Pages\RecordDetailPage;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

final class ViewComplianceCase extends RecordDetailPage
{
    protected static string $resource = ComplianceCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ComplianceActions::caseGroup(),
            Action::make('transition')->schema([Select::make('to_status')->options(['UNDER_REVIEW' => 'Under review', 'REMEDIATION' => 'Remediation', 'CLOSED' => 'Closed', 'REOPENED' => 'Reopened'])->required(), TextInput::make('reason_code')->required(), KeyValue::make('findings')])
                ->action(function (array $d) {
                    ServiceValidation::run(fn () => app(ComplianceCaseService::class)->transition($this->record, $d['to_status'], $d['reason_code'], $d['findings'] ?? [], auth()->user()));
                }),
        ];
    }

    /** Generic record details, then the case's findings and corrective actions (acted on through the case actions menu). */
    public function infolist(Schema $schema): Schema
    {
        $id = $this->getRecord()->getKey();
        $f = fn (string $k) => __('compliance_actions.fields.'.$k);
        $findings = fn () => DB::table('compliance_findings')->where('compliance_case_id', $id)->orderBy('created_at')->get()->map(fn ($r) => (array) $r)->all();
        $actions = fn () => DB::table('compliance_corrective_actions')->where('compliance_case_id', $id)->orderBy('due_on')->get()->map(fn ($r) => (array) $r)->all();

        return $schema->components([
            ...RecordInfolist::for($this->getRecord(), static::$auditSubjectType),
            Section::make(__('compliance_actions.sections.findings'))->columnSpanFull()->schema([
                RepeatableEntry::make('compliance_findings')->hiddenLabel()->state($findings)->columns(4)->schema([
                    TextEntry::make('title')->label($f('title')),
                    TextEntry::make('severity')->label($f('severity'))->badge(),
                    TextEntry::make('category')->label($f('category'))->placeholder('—'),
                    TextEntry::make('status')->label($f('status'))->badge(),
                ]),
            ]),
            Section::make(__('compliance_actions.sections.corrective_actions'))->columnSpanFull()->schema([
                RepeatableEntry::make('compliance_corrective_actions')->hiddenLabel()->state($actions)->columns(3)->schema([
                    TextEntry::make('description')->label($f('description')),
                    TextEntry::make('due_on')->label($f('due_on'))->date(),
                    TextEntry::make('status')->label($f('status'))->badge(),
                ]),
            ]),
        ]);
    }
}
