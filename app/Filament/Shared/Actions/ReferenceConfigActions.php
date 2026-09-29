<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Configuration\ConfigurationInheritance;
use App\Application\Configuration\RegulatoryReferenceSetService;
use App\Application\ReferenceDatasets\PublicHolidayGenerator;
use App\Application\ReferenceDatasets\ReferenceDatasetService;
use App\Application\Rules\Models\QuestionSet;
use App\Application\Rules\QuestionSetCatalogue;
use App\Application\WebExperiences\PortalWorkspaceService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\InsuranceProduct;
use App\Models\MarketplacePublication;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Question sets, reference datasets, regulatory reference sets, configuration overrides and marketplace publications
 * (UI coverage batch 28). Same permission, validation and service call as the API:
 *   qsCreate              POST question-sets                                 rules.manage                     QuestionSetCatalogue::createDraft
 *   qsSubmit              POST question-sets/{s}/submit                      rules.manage                     QuestionSetCatalogue::submit
 *   qsApprove / qsReject  POST question-sets/{s}/approve|reject              rules.approve                    QuestionSetCatalogue::decide
 *   rdDraft               POST reference-datasets                            reference_datasets.manage        ReferenceDatasetService::draft
 *   rdGenerateHolidays    POST reference-datasets/public-holidays/generate   reference_datasets.manage        PublicHolidayGenerator::draft
 *   rdActivate            POST reference-datasets/{id}/activate              reference_datasets.approve       ReferenceDatasetService::activate
 *   rdRetire              POST reference-datasets/{id}/retire                reference_datasets.approve       ReferenceDatasetService::retire
 *   rrsDraft              POST configuration/regulatory-reference-sets       configuration.regulatory.manage  RegulatoryReferenceSetService::draft
 *   rrsApprove            POST configuration/regulatory-reference-sets/{s}/approve configuration.regulatory.approve RegulatoryReferenceSetService::approve
 *   cfgDraftOverride      POST configuration/inheritance/overrides           configuration.changes.manage     ConfigurationInheritance::draftOverride
 *   mpPublish             POST web-experiences/marketplace/publications      policy create (marketplace.publications.create)  PortalWorkspaceService::publish
 *   mpApprove             POST web-experiences/marketplace/publications/{p}/approve policy approve (marketplace.publications.approve, not the maker) PortalWorkspaceService::approve
 */
final class ReferenceConfigActions
{
    private const LANG = 'catalogue_actions';

    // ── Question sets ──────────────────────────────────────────────────
    public static function qsCreate(): Action
    {
        $p = 'rules.manage';

        return WorkflowAction::make('qsCreate', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                Select::make('line_code')->label(__('catalogue_actions.fields.line'))->native(false)
                    ->options(fn () => \App\Models\InsuranceLine::orderBy('code')->pluck('code', 'code')->all()),
                Select::make('insurance_product_id')->label(__('catalogue_actions.fields.product_version'))->searchable()
                    ->options(fn () => InsuranceProduct::orderBy('code')->get()->mapWithKeys(fn ($v) => [$v->id => $v->code.' v'.$v->version])->all()),
                Select::make('stage')->label(__('catalogue_actions.fields.stage'))->native(false)->options(array_combine(QuestionSetCatalogue::STAGES, QuestionSetCatalogue::STAGES)),
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('catalogue_actions.fields.effective_until'))->afterOrEqual('effective_from'),
                Textarea::make('schema')->label(__('catalogue_actions.fields.schema_json'))->required()->rows(10)->helperText(__('catalogue_actions.fields.schema_help')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $in = CatalogueActions::clean($data);
                $in['schema'] = self::json($data['schema'] ?? null, 'schema');
                $d = Validator::validate($in, [
                    'line_code' => 'required_without:insurance_product_id|nullable|string|max:32|exists:insurance_lines,code',
                    'insurance_product_id' => 'nullable|uuid|exists:insurance_products,id', 'stage' => ['nullable', Rule::in(QuestionSetCatalogue::STAGES)],
                    'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
                    'schema' => 'required|array', 'schema.fields' => 'required|array|min:1|max:500', 'schema.steps' => 'nullable|array', 'schema.required' => 'nullable|array',
                ]);

                return app(QuestionSetCatalogue::class)->createDraft(['schema' => $in['schema']] + $d, auth()->user());
            }, __('catalogue_actions.qsCreate.done')));
    }

    public static function qsSubmit(): Action
    {
        $p = 'rules.manage';

        return WorkflowAction::make('qsSubmit', $p, self::LANG)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (QuestionSet $record) => $record->status === 'DRAFT')
            ->action(fn (Action $action, QuestionSet $record) => WorkflowAction::run($action, $p,
                fn () => app(QuestionSetCatalogue::class)->submit($record->refresh(), auth()->user()), __('catalogue_actions.qsSubmit.done')));
    }

    public static function qsApprove(): Action
    {
        $p = 'rules.approve';

        return WorkflowAction::make('qsApprove', $p, self::LANG)->icon('lucide-badge-check')->color('success')
            ->visible(fn (QuestionSet $record) => $record->status === 'IN_REVIEW')
            ->schema([Textarea::make('note')->label(__('catalogue_actions.fields.note'))->maxLength(500)])
            ->action(fn (Action $action, QuestionSet $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(QuestionSetCatalogue::class)->decide($record->refresh(), auth()->user(), true, filled($data['note'] ?? null) ? $data['note'] : null), __('catalogue_actions.qsApprove.done')));
    }

    public static function qsReject(): Action
    {
        $p = 'rules.approve';

        return WorkflowAction::make('qsReject', $p, self::LANG)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (QuestionSet $record) => $record->status === 'IN_REVIEW')
            ->schema([Textarea::make('note')->label(__('catalogue_actions.fields.note'))->required()->minLength(3)->maxLength(500)])
            ->action(fn (Action $action, QuestionSet $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(QuestionSetCatalogue::class)->decide($record->refresh(), auth()->user(), false, $data['note']), __('catalogue_actions.qsReject.done')));
    }

    // ── Reference datasets ─────────────────────────────────────────────
    public static function rdDraft(): Action
    {
        $p = 'reference_datasets.manage';

        return WorkflowAction::make('rdDraft', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                Select::make('kind')->label(__('catalogue_actions.fields.kind'))->required()->native(false)->options(array_combine(ReferenceDatasetService::KINDS, ReferenceDatasetService::KINDS)),
                TextInput::make('jurisdiction')->label(__('catalogue_actions.fields.jurisdiction'))->required()->length(2)->default('CM'),
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(80)->regex('/^[A-Z][A-Z0-9_]*$/'),
                TextInput::make('source_name')->label(__('catalogue_actions.fields.source_name'))->required()->maxLength(255),
                TextInput::make('source_reference')->label(__('catalogue_actions.fields.source_reference'))->maxLength(255),
                TextInput::make('source_url')->label(__('catalogue_actions.fields.source_url'))->url()->maxLength(500),
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('catalogue_actions.fields.effective_until'))->afterOrEqual('effective_from'),
                Select::make('verification_status')->label(__('catalogue_actions.fields.verification_status'))->native(false)->options(['UNVERIFIED' => 'UNVERIFIED', 'DISPUTED' => 'DISPUTED']),
                Textarea::make('verification_note')->label(__('catalogue_actions.fields.verification_note'))->maxLength(2000),
                Textarea::make('entries')->label(__('catalogue_actions.fields.entries_json'))->required()->rows(8)->helperText(__('catalogue_actions.fields.entries_help')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $in = CatalogueActions::clean(array_diff_key($data, ['entries' => 1]));
                $in['entries'] = self::json($data['entries'] ?? null, 'entries');
                $d = Validator::validate($in, [
                    'kind' => ['required', Rule::in(ReferenceDatasetService::KINDS)], 'jurisdiction' => 'required|string|size:2',
                    'code' => 'required|string|max:80|regex:/^[A-Z][A-Z0-9_]*$/', 'source_name' => 'required|string|max:255',
                    'source_reference' => 'nullable|string|max:255', 'source_url' => 'nullable|url|max:500',
                    'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
                    'verification_status' => 'nullable|in:UNVERIFIED,DISPUTED', 'verification_note' => 'nullable|string|max:2000',
                    'entries' => 'required|array|min:1|max:5000',
                ] + (($in['kind'] ?? null) === 'PUBLIC_HOLIDAYS' ? [
                    'entries.*.date' => 'required|date_format:Y-m-d', 'entries.*.label' => 'required|string|max:160',
                    'entries.*.holiday_type' => 'nullable|in:PUBLIC,RELIGIOUS_MOVABLE,DECREED', 'entries.*.legal_reference' => 'nullable|string|max:255',
                ] : [
                    'entries.*.hazard_type' => 'required|string|max:32|regex:/^[A-Z][A-Z0-9_]*$/', 'entries.*.zone_code' => 'required|string|max:64',
                    'entries.*.name' => 'required|string|max:160', 'entries.*.admin_area_code' => 'nullable|string|max:64',
                    'entries.*.hazard_level' => 'nullable|string|max:24', 'entries.*.geometry' => 'nullable|array', 'entries.*.attributes' => 'nullable|array',
                ]));

                return app(ReferenceDatasetService::class)->draft($d, auth()->user());
            }, __('catalogue_actions.rdDraft.done')));
    }

    public static function rdGenerateHolidays(): Action
    {
        $p = 'reference_datasets.manage';

        return WorkflowAction::make('rdGenerateHolidays', $p, self::LANG)->icon('lucide-calendar-plus')
            ->schema([
                TextInput::make('year')->label(__('catalogue_actions.fields.year'))->required()->integer()->minValue(2000)->maxValue(2100)->default((int) now()->year + 1),
                TextInput::make('jurisdiction')->label(__('catalogue_actions.fields.jurisdiction'))->length(2)->default('CM'),
                Textarea::make('extra')->label(__('catalogue_actions.fields.extra_json'))->rows(5)->helperText(__('catalogue_actions.fields.extra_help')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $in = CatalogueActions::clean(array_diff_key($data, ['extra' => 1]));
                $in['extra'] = filled($data['extra'] ?? null) ? self::json($data['extra'], 'extra') : null;
                $d = Validator::validate($in, [
                    'year' => 'required|integer|between:2000,2100', 'jurisdiction' => 'nullable|string|size:2',
                    'extra' => 'nullable|array|max:20', 'extra.*.code' => 'nullable|string|max:64', 'extra.*.date' => 'required|date_format:Y-m-d',
                    'extra.*.label' => 'required|string|max:160', 'extra.*.holiday_type' => 'nullable|in:PUBLIC,RELIGIOUS_MOVABLE,DECREED',
                    'extra.*.legal_reference' => 'required|string|max:255',
                ]);
                foreach ($d['extra'] ?? [] as $e) {
                    if ((int) substr($e['date'], 0, 4) !== (int) $d['year']) {
                        throw ValidationException::withMessages(['extra' => 'Extra holiday dates must fall in the requested year.']);
                    }
                }

                return app(PublicHolidayGenerator::class)->draft((int) $d['year'], auth()->user(), strtoupper($d['jurisdiction'] ?? 'CM'), $d['extra'] ?? []);
            }, __('catalogue_actions.rdGenerateHolidays.done')));
    }

    public static function rdActivate(): Action
    {
        $p = 'reference_datasets.approve';

        return WorkflowAction::make('rdActivate', $p, self::LANG)->icon('lucide-badge-check')->color('success')
            ->visible(fn ($record) => self::field($record, 'status') === 'DRAFT')
            ->schema([
                Select::make('verification_status')->label(__('catalogue_actions.fields.verification_status'))->native(false)
                    ->options(['UNVERIFIED' => 'UNVERIFIED', 'VERIFIED' => 'VERIFIED', 'DISPUTED' => 'DISPUTED']),
                Textarea::make('verification_note')->label(__('catalogue_actions.fields.verification_note'))->maxLength(2000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = CatalogueActions::clean($data);

                return app(ReferenceDatasetService::class)->activate(WorkflowAction::id($record), auth()->user(), $d['verification_status'] ?? null, $d['verification_note'] ?? null);
            }, __('catalogue_actions.rdActivate.done')));
    }

    public static function rdRetire(): Action
    {
        $p = 'reference_datasets.approve';

        return WorkflowAction::make('rdRetire', $p, self::LANG)->icon('lucide-archive')->color('danger')
            ->visible(fn ($record) => in_array(self::field($record, 'status'), ['DRAFT', 'ACTIVE'], true))
            ->schema([Textarea::make('reason')->label(__('catalogue_actions.fields.reason'))->required()->minLength(5)->maxLength(2000)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ReferenceDatasetService::class)->retire(WorkflowAction::id($record), $data['reason']), __('catalogue_actions.rdRetire.done')));
    }

    // ── Regulatory reference sets ──────────────────────────────────────
    public static function rrsDraft(): Action
    {
        $p = 'configuration.regulatory.manage';

        return WorkflowAction::make('rrsDraft', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                TextInput::make('jurisdiction')->label(__('catalogue_actions.fields.jurisdiction'))->required()->length(2)->default('CM'),
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(80),
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('catalogue_actions.fields.effective_until'))->after('effective_from'),
                Textarea::make('entries')->label(__('catalogue_actions.fields.entries_json'))->required()->rows(8),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $in = CatalogueActions::clean(array_diff_key($data, ['entries' => 1]));
                $in['entries'] = self::json($data['entries'] ?? null, 'entries');
                $d = Validator::validate($in, ['jurisdiction' => 'required|string|size:2', 'code' => 'required|string|max:80', 'effective_from' => 'required|date',
                    'effective_until' => 'nullable|date|after:effective_from', 'entries' => 'required|array|min:1']);

                return app(RegulatoryReferenceSetService::class)->draft($d);
            }, __('catalogue_actions.rrsDraft.done')));
    }

    public static function rrsApprove(): Action
    {
        $p = 'configuration.regulatory.approve';

        return WorkflowAction::make('rrsApprove', $p, self::LANG)->icon('lucide-badge-check')->color('success')
            ->visible(fn ($record) => self::field($record, 'status') === 'DRAFT')
            ->schema([Textarea::make('reason')->label(__('catalogue_actions.fields.reason'))->required()->minLength(20)->maxLength(2000)])
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(RegulatoryReferenceSetService::class)->approve(WorkflowAction::id($record), auth()->user()), __('catalogue_actions.rrsApprove.done')));
    }

    // ── Configuration inheritance ──────────────────────────────────────
    public static function cfgDraftOverride(): Action
    {
        $p = 'configuration.changes.manage';
        $scopeFields = ['insurer_id' => 'INSURER', 'agreement_id' => 'BROKER_AGREEMENT', 'broker_id' => 'BROKER', 'branch_id' => 'BRANCH', 'user_id' => 'USER'];

        return WorkflowAction::make('cfgDraftOverride', $p, self::LANG)->icon('lucide-sliders-horizontal')
            ->schema(fn () => [
                Select::make('scope_level')->label(__('catalogue_actions.fields.scope_level'))->required()->native(false)
                    ->options(fn () => array_combine($l = app(ConfigurationInheritance::class)->levels(), $l)),
                TextInput::make('scope_id')->label(__('catalogue_actions.fields.scope_id'))->uuid(),
                Textarea::make('value')->label(__('catalogue_actions.fields.value_json'))->required()->helperText(__('catalogue_actions.fields.value_help')),
                Textarea::make('reason')->label(__('catalogue_actions.fields.reason'))->required()->minLength(5)->maxLength(2000),
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from')),
                ...array_map(fn ($f) => TextInput::make($f)->label(__("catalogue_actions.fields.{$f}"))->uuid(), array_keys($scopeFields)),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data, $scopeFields) {
                $inheritance = app(ConfigurationInheritance::class);
                $raw = trim((string) ($data['value'] ?? ''));
                $decoded = json_decode($raw, true);
                $in = CatalogueActions::clean(array_diff_key($data, ['value' => 1])) + ['key' => WorkflowAction::id($record), 'value' => json_last_error() === JSON_ERROR_NONE ? $decoded : $raw];
                $d = Validator::validate($in, ['key' => 'required|string|max:120', 'scope_level' => 'required|string|in:'.implode(',', $inheritance->levels()), 'scope_id' => 'nullable|uuid',
                    'value' => 'present', 'reason' => 'required|string|min:5|max:2000', 'effective_from' => 'nullable|date', ...array_fill_keys(array_keys($scopeFields), 'nullable|uuid')]);
                $ancestors = [];
                foreach ($scopeFields as $field => $level) {
                    if (! empty($d[$field])) {
                        $ancestors[$level] = $d[$field];
                    }
                }

                return $inheritance->draftOverride(auth()->user(), $d['key'], $d['scope_level'], $d['scope_id'] ?? null, $d['value'], $d['reason'], $ancestors, $d['effective_from'] ?? null);
            }, __('catalogue_actions.cfgDraftOverride.done')));
    }

    // ── Marketplace publications ───────────────────────────────────────
    public static function mpPublish(): Action
    {
        $p = 'marketplace.publications.create';

        return WorkflowAction::make('mpPublish', $p, self::LANG)->icon('lucide-store')
            ->schema([
                Select::make('product_id')->label(__('catalogue_actions.fields.product_version'))->required()->searchable()
                    ->options(fn () => InsuranceProduct::where('status', 'ACTIVE')->orderBy('code')->get()->mapWithKeys(fn ($v) => [$v->id => $v->code.' v'.$v->version.' — '.$v->name])->all()),
                Select::make('channels')->label(__('catalogue_actions.fields.channels'))->required()->multiple()->options(['WEB' => 'WEB'])->default(['WEB']),
                DateTimePicker::make('starts_at')->label(__('catalogue_actions.fields.starts_at')),
                DateTimePicker::make('ends_at')->label(__('catalogue_actions.fields.ends_at'))->after('starts_at'),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                if (Gate::denies('create', MarketplacePublication::class)) {
                    throw new ApiProblemException('FORBIDDEN', 403, __('workflow_actions.denied'));
                }
                $d = Validator::validate(CatalogueActions::clean($data), ['product_id' => 'required|uuid', 'tariff_version_id' => 'nullable|uuid', 'channels' => 'required|array|min:1',
                    'channels.*' => 'in:WEB', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at']);

                return app(PortalWorkspaceService::class)->publish((string) app(TenantContext::class)->id(), array_filter($d, fn ($v) => $v !== null), auth()->user());
            }, __('catalogue_actions.mpPublish.done')));
    }

    public static function mpApprove(): Action
    {
        $p = 'marketplace.publications.approve';

        return WorkflowAction::make('mpApprove', $p, self::LANG)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (MarketplacePublication $record) => $record->status === 'DRAFT')
            ->action(fn (Action $action, MarketplacePublication $record) => WorkflowAction::run($action, $p, function () use ($record) {
                if ($record->tenant_id !== app(TenantContext::class)->id()) {
                    throw new ApiProblemException('NOT_FOUND', 404, 'Publication not found.');
                }
                if (Gate::denies('approve', $record)) {
                    throw new ApiProblemException('FORBIDDEN', 403, __('catalogue_actions.mpApprove.maker_checker'));
                }

                return app(PortalWorkspaceService::class)->approve($record, auth()->user(), (int) $record->version);
            }, __('catalogue_actions.mpApprove.done')));
    }

    // ── helpers ────────────────────────────────────────────────────────
    private static function json(?string $raw, string $field): mixed
    {
        $v = json_decode(trim((string) $raw), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($v)) {
            throw ValidationException::withMessages([$field => __('catalogue_actions.invalid_json')]);
        }

        return $v;
    }

    private static function field(mixed $record, string $key): mixed
    {
        return is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);
    }
}
