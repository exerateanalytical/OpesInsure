<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Compliance\Aml\Risk\AmlRiskRatingService;
use App\Application\Compliance\Aml\Risk\TransactionMonitoringService;
use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListSource;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListVersion;
use App\Application\Compliance\Aml\Screening\ScreeningListService;
use App\Application\Compliance\Aml\Screening\ScreeningService;
use App\Application\Compliance\Aml\Str\StrService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Party;
use App\Models\TenantCustomer;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AML web actions (REQ-AML-001/002/003). Same service, same validation, same permission as the API route:
 *   screenParty       POST aml/screening/parties/{p}/screen            aml.screening.run                    ScreeningService::screenParty
 *   hitPropose        POST aml/screening/hits/{h}/disposition          aml.screening.disposition.propose    ScreeningService::propose
 *   hitDecide         POST aml/screening/hits/{h}/disposition/decide   aml.screening.disposition.approve    ScreeningService::decide
 *   listCreate        POST aml/screening/lists                         aml.screening.lists.manage           ScreeningListService::createSource
 *   listImport        POST aml/screening/lists/{s}/versions            aml.screening.lists.manage           ScreeningListService::import
 *   listVersionDecide POST aml/screening/list-versions/{v}/decide      aml.screening.lists.approve          ScreeningListService::decide
 *   riskRate          POST aml/customers/{p}/risk-rating               aml.risk.rate                        AmlRiskRatingService::rate
 *   monitorEvaluate   POST aml/transaction-monitoring/evaluate         aml.monitoring.evaluate              TransactionMonitoringService::evaluate
 *   strDraft          POST aml/str-reports                             cases.str.view (StrService, else 404) StrService::draft
 *   strSubmit         POST aml/str-reports/{s}/submit                  cases.str.view (StrService, else 404) StrService::submit
 * STR actions are hidden from anyone without cases.str.view: for them an STR does not exist (tipping-off, Reg. 003-25).
 */
final class AmlActions
{
    public const L = 'aml_actions';

    public static function screenParty(): Action
    {
        $p = 'aml.screening.run';

        return WorkflowAction::make('screenParty', $p, self::L)->icon('lucide-scan-search')->requiresConfirmation()
            ->action(fn (Action $action, Model $record) => WorkflowAction::run($action, $p, function () use ($record) {
                $party = self::customer($record);

                return app(ScreeningService::class)->screenParty(self::tenant(), $party, 'MANUAL_RESCREEN', auth()->user());
            }, __(self::L.'.screenParty.done')));
    }

    public static function riskRate(): Action
    {
        $p = 'aml.risk.rate';

        return WorkflowAction::make('riskRate', $p, self::L)->icon('lucide-gauge')
            ->schema([
                TextInput::make('country_code')->label(__(self::L.'.fields.country_code'))->length(2),
                TagsInput::make('product_codes')->label(__(self::L.'.fields.product_codes'))->rules(['array', 'max:50'])->nestedRecursiveRules(['string', 'max:64']),
                TextInput::make('channel')->label(__(self::L.'.fields.channel'))->maxLength(40),
                Select::make('customer_type')->label(__(self::L.'.fields.customer_type'))->options(self::codes(['INDIVIDUAL', 'CORPORATE'], 'customer_type')),
                Textarea::make('reason')->label(__(self::L.'.fields.reason'))->required()->maxLength(500),
            ])
            ->action(function (Action $action, Model $record, array $data) use ($p) {
                $inputs = array_filter(['country_code' => filled($data['country_code'] ?? null) ? strtoupper($data['country_code']) : null,
                    'product_codes' => ! empty($data['product_codes']) ? array_values($data['product_codes']) : null,
                    'channel' => filled($data['channel'] ?? null) ? strtoupper($data['channel']) : null,
                    'customer_type' => $data['customer_type'] ?? null], fn ($v) => $v !== null);

                return WorkflowAction::run($action, $p, fn () => app(AmlRiskRatingService::class)->rate(self::tenant(), self::partyId($record), $inputs, $data['reason'], auth()->user()),
                    __(self::L.'.riskRate.done'));
            });
    }

    public static function hitPropose(): Action
    {
        $p = 'aml.screening.disposition.propose';

        return WorkflowAction::make('hitPropose', $p, self::L)->icon('lucide-file-pen')
            ->visible(fn (ScreeningHit $record) => $record->status !== 'DISPOSED')
            ->schema([
                Select::make('disposition')->label(__(self::L.'.fields.disposition'))->options(self::codes(ScreeningHit::DISPOSITIONS, 'disposition'))->required(),
                Textarea::make('rationale')->label(__(self::L.'.fields.rationale'))->required()->maxLength(4000),
            ])
            ->action(fn (Action $action, ScreeningHit $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ScreeningService::class)->propose(self::hit($record), $data['disposition'], $data['rationale'], auth()->user()), __(self::L.'.hitPropose.done')));
    }

    public static function hitDecide(): Action
    {
        $p = 'aml.screening.disposition.approve';

        return WorkflowAction::make('hitDecide', $p, self::L)->icon('lucide-badge-check')
            ->visible(fn (ScreeningHit $record) => $record->status === 'PROPOSED')
            ->schema(self::decisionFields(4000))
            ->action(fn (Action $action, ScreeningHit $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ScreeningService::class)->decide(self::hit($record), $data['decision'] === 'APPROVE', filled($data['note'] ?? null) ? $data['note'] : null, auth()->user()),
                __(self::L.'.hitDecide.done')));
    }

    public static function listCreate(): Action
    {
        $p = 'aml.screening.lists.manage';

        return WorkflowAction::make('listCreate', $p, self::L)->icon('lucide-list-plus')
            ->schema([
                TextInput::make('code')->label(__(self::L.'.fields.code'))->required()->maxLength(64)->regex('/^[A-Za-z0-9_.-]+$/'),
                TextInput::make('name')->label(__(self::L.'.fields.name'))->required()->maxLength(160),
                Select::make('list_type')->label(__(self::L.'.fields.list_type'))->options(self::codes(ScreeningListService::LIST_TYPES, 'list_type'))->required(),
                TextInput::make('publisher')->label(__(self::L.'.fields.publisher'))->maxLength(160),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(ScreeningListService::class)->createSource(self::tenant(),
                array_filter(['code' => $data['code'], 'name' => $data['name'], 'list_type' => $data['list_type'], 'publisher' => $data['publisher'] ?? null], fn ($v) => $v !== null),
                auth()->user()), __(self::L.'.listCreate.done')));
    }

    /** CSV import (the API's JSON variant carries the same entries; the desktop form takes the CSV text). */
    public static function listImport(): Action
    {
        $p = 'aml.screening.lists.manage';

        return WorkflowAction::make('listImport', $p, self::L)->icon('lucide-upload')
            ->schema([
                Select::make('source_id')->label(__(self::L.'.fields.source'))->required()
                    ->options(fn () => ScreeningListSource::where('tenant_id', self::tenant())->orderBy('code')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->code} — {$s->name}"])->all()),
                Textarea::make('content')->label(__(self::L.'.fields.csv_content'))->helperText(__(self::L.'.fields.csv_help'))->required()->rows(8),
                TextInput::make('source_reference')->label(__(self::L.'.fields.source_reference'))->maxLength(255),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($data) {
                    $src = ScreeningListSource::where('tenant_id', self::tenant())->whereKey($data['source_id'])->firstOrFail();

                    return app(ScreeningListService::class)->import($src, 'CSV', (string) $data['content'], filled($data['source_reference'] ?? null) ? $data['source_reference'] : null, auth()->user());
                }, __(self::L.'.listImport.done'));
            });
    }

    public static function listVersionDecide(): Action
    {
        $p = 'aml.screening.lists.approve';

        return WorkflowAction::make('listVersionDecide', $p, self::L)->icon('lucide-badge-check')
            ->visible(fn (ScreeningListVersion $record) => $record->status === 'PENDING_APPROVAL')
            ->schema(self::decisionFields(2000))
            ->action(fn (Action $action, ScreeningListVersion $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $v = ScreeningListVersion::where('tenant_id', self::tenant())->whereKey($record->getKey())->firstOrFail();

                return app(ScreeningListService::class)->decide($v, $data['decision'] === 'APPROVE', filled($data['note'] ?? null) ? $data['note'] : null, auth()->user());
            }, __(self::L.'.listVersionDecide.done')));
    }

    public static function monitorEvaluate(): Action
    {
        $p = 'aml.monitoring.evaluate';

        return WorkflowAction::make('monitorEvaluate', $p, self::L)->icon('lucide-activity')
            ->schema([
                Select::make('subject_type')->label(__(self::L.'.fields.subject_type'))->options(self::codes(['PAYMENT', 'POLICY', 'CLAIM', 'COMMISSION', 'REFUND'], 'subject_type'))->required(),
                TextInput::make('subject_id')->label(__(self::L.'.fields.subject_id'))->required()->uuid(),
                TextInput::make('party_id')->label(__(self::L.'.fields.party_id'))->uuid(),
                KeyValue::make('facts')->label(__(self::L.'.fields.facts'))->keyLabel(__(self::L.'.fields.fact'))->valueLabel(__(self::L.'.fields.value'))->required(),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $facts = array_map(fn ($v) => is_numeric($v) ? $v + 0 : $v, (array) ($data['facts'] ?? []));
                $txn = ['subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id'], 'party_id' => filled($data['party_id'] ?? null) ? $data['party_id'] : null, 'facts' => $facts];
                $r = WorkflowAction::run($action, $p, fn () => app(TransactionMonitoringService::class)->evaluate(self::tenant(), $txn),
                    __(self::L.'.monitorEvaluate.done'));
                if (is_array($r)) {
                    Notification::make()->info()->title(__(self::L.'.monitorEvaluate.result', ['status' => $r['status'], 'alerts' => count($r['alerts'])]))->send();
                }

                return $r;
            });
    }

    public static function strDraft(): Action
    {
        $p = StrService::PERMISSION;

        return WorkflowAction::make('strDraft', $p, self::L)->icon('lucide-file-warning')
            ->schema([
                Select::make('party_id')->label(__(self::L.'.fields.party'))->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Party::whereHas('customers', fn ($q) => $q->where('tenant_id', self::tenant()))
                        ->where('display_name', 'ilike', '%'.$search.'%')->limit(25)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Party::whereKey($value)->value('display_name')),
                Textarea::make('grounds')->label(__(self::L.'.fields.grounds'))->required()->minLength(20)->maxLength(8000)->rows(6),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(StrService::class)->draft(self::tenant(),
                ['party_id' => filled($data['party_id'] ?? null) ? $data['party_id'] : null, 'grounds' => $data['grounds']], auth()->user()), __(self::L.'.strDraft.done')));
    }

    public static function strSubmit(): Action
    {
        $p = StrService::PERMISSION;

        return WorkflowAction::make('strSubmit', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->schema([TextInput::make('regulator_reference')->label(__(self::L.'.fields.regulator_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(StrService::class)->submit(self::tenant(), WorkflowAction::id($record), $data['regulator_reference'], auth()->user()), __(self::L.'.strSubmit.done')));
    }

    /** @return list<Component|Field> */
    private static function decisionFields(int $noteMax): array
    {
        return [
            Select::make('decision')->label(__(self::L.'.fields.decision'))->options(self::codes(['APPROVE', 'REJECT'], 'decision'))->required(),
            Textarea::make('note')->label(__(self::L.'.fields.note'))->maxLength($noteMax),
        ];
    }

    /** @param list<string> $values @return array<string, string> */
    public static function codes(array $values, string $group): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => WorkflowAction::optional(self::L.".codes.{$group}.{$v}") ?? $v])->all();
    }

    private static function hit(ScreeningHit $record): ScreeningHit
    {
        return ScreeningHit::where('tenant_id', self::tenant())->whereKey($record->getKey())->firstOrFail();
    }

    private static function partyId(Model $record): string
    {
        return $record instanceof TenantCustomer ? (string) $record->party_id : (string) $record->getKey();
    }

    /** As the API: the party must be a customer of the tenant (tenant_customers) to be screened here, else 404. */
    private static function customer(Model $record): Party
    {
        $id = self::partyId($record);
        abort_unless(DB::table('tenant_customers')->where('tenant_id', self::tenant())->where('party_id', $id)->exists(), 404);

        return Party::findOrFail($id);
    }

    private static function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }
}
