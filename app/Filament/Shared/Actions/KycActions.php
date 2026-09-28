<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Kyc\KycRequirementService;
use App\Application\Kyc\KycService;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Document;
use App\Models\KycSubmission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;

/**
 * KYC review detail-page actions (KycSubmissionResource view). Same permission + service + validation as
 * App\Application\Kyc\Http\KycController (routes/kyc.php):
 *   kycAttachDocument    POST kyc/submissions/{s}/documents            kyc.manage  KycService::attachDocument
 *   kycDeclareSources    POST kyc/submissions/{s}/sources              kyc.manage  KycService::declareSources
 *   kycStartReview       POST kyc/submissions/{s}/start-review         kyc.review  KycService::startReview
 *   kycSetLevel          POST kyc/submissions/{s}/level                kyc.review  KycService::setLevel
 *   kycAssessRisk        POST kyc/submissions/{s}/risk-assessment      kyc.review  KycService::assessRisk
 *   kycRecordScreening   POST kyc/submissions/{s}/screenings/{check}   kyc.screen  KycService::recordScreening
 *   kycRequestInformation POST kyc/submissions/{s}/request-information kyc.review KycService::requestInformation
 *   kycRecommend         POST kyc/submissions/{s}/recommend            kyc.review  KycService::recommend
 *   kycDecide            POST kyc/submissions/{s}/decision             kyc.decide  KycService::decide
 *   kycRescreen          POST kyc/submissions/{s}/rescreen             kyc.screen  KycService::rescreen
 *   kycRemediate         POST kyc/submissions/{s}/remediate            kyc.manage  KycService::remediate
 * Visibility mirrors the statuses each service method accepts.
 */
final class KycActions
{
    private const LANG = 'kyc_actions';

    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::attachDocument(), self::declareSources(), self::startReview(), self::setLevel(), self::assessRisk(), self::recordScreening(),
            self::requestInformation(), self::recommend(), self::decide(), self::rescreen(), self::remediate(),
        ])->label(__('kyc_actions.group'))->icon('lucide-zap')->button();
    }

    public static function attachDocument(): Action
    {
        $p = 'kyc.manage';

        return WorkflowAction::make('kycAttachDocument', $p, self::LANG)->icon('lucide-paperclip')
            ->visible(fn (KycSubmission $record) => in_array($record->status, KycService::EDITABLE, true))
            ->schema(fn (KycSubmission $record) => [
                Select::make('document_id')->label(__('kyc_actions.fields.document'))->required()->searchable()
                    ->options(fn () => self::documents($record)),
                TextInput::make('purpose')->label(__('kyc_actions.fields.purpose'))->required()->maxLength(64),
            ])
            ->action(function (Action $action, KycSubmission $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $s = self::submission($record);
                    $doc = Document::where('tenant_id', $s->tenant_id)->whereKey($data['document_id'])->first()
                        ?? throw new ApiProblemException('DOCUMENT_NOT_FOUND', 404, 'Document not found in this tenant.');

                    return app(KycService::class)->attachDocument($s, $doc, $data['purpose'], auth()->user());
                }, __('kyc_actions.kycAttachDocument.done'));
            });
    }

    public static function declareSources(): Action
    {
        $p = 'kyc.manage';
        $block = fn (string $k) => Fieldset::make(__("kyc_actions.fields.{$k}"))->columns(1)->schema([
            Textarea::make("{$k}.description")->label(__('kyc_actions.fields.description'))->maxLength(2000),
            TextInput::make("{$k}.origin")->label(__('kyc_actions.fields.origin'))->maxLength(120),
            Select::make("{$k}.evidence_document_ids")->label(__('kyc_actions.fields.evidence_documents'))->multiple()->maxItems(20)
                ->options(fn (?KycSubmission $record) => $record ? self::documents($record) : []),
        ]);

        return WorkflowAction::make('kycDeclareSources', $p, self::LANG)->icon('lucide-landmark')
            ->visible(fn (KycSubmission $record) => in_array($record->status, [...KycService::EDITABLE, 'SUBMITTED', 'REVIEWING'], true))
            ->schema([$block('source_of_funds'), $block('source_of_wealth'),
                Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(500)])
            ->action(function (Action $action, KycSubmission $record, array $data) use ($p) {
                $clean = function (?array $x): ?array {
                    if (! $x || ! filled($x['description'] ?? null)) {
                        return null;
                    }

                    return array_filter(['description' => $x['description'], 'origin' => filled($x['origin'] ?? null) ? $x['origin'] : null,
                        'evidence_document_ids' => array_values($x['evidence_document_ids'] ?? []) ?: null], fn ($v) => $v !== null);
                };
                $funds = $clean($data['source_of_funds'] ?? null);
                $wealth = $clean($data['source_of_wealth'] ?? null);

                return WorkflowAction::run($action, $p, function () use ($record, $funds, $wealth, $data) {
                    if ($funds === null && $wealth === null) {
                        throw new ApiProblemException('VALIDATION_FAILED', 422, __('kyc_actions.kycDeclareSources.missing'));
                    }

                    return app(KycService::class)->declareSources(self::submission($record), $funds, $wealth, $data['reason'], auth()->user());
                }, __('kyc_actions.kycDeclareSources.done'));
            });
    }

    public static function startReview(): Action
    {
        $p = 'kyc.review';

        return WorkflowAction::make('kycStartReview', $p, self::LANG)->icon('lucide-play')->requiresConfirmation()
            ->visible(fn (KycSubmission $record) => $record->status === 'SUBMITTED')
            ->action(fn (Action $action, KycSubmission $record) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->startReview(self::submission($record), auth()->user()), __('kyc_actions.kycStartReview.done')));
    }

    public static function setLevel(): Action
    {
        $p = 'kyc.review';

        return WorkflowAction::make('kycSetLevel', $p, self::LANG)->icon('lucide-layers')
            ->visible(fn (KycSubmission $record) => in_array($record->status, ['SUBMITTED', 'REVIEWING'], true))
            ->schema([
                Select::make('kyc_level')->label(__('kyc_actions.fields.kyc_level'))->required()->options(self::codes(KycRequirementService::LEVELS, 'level')),
                Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->setLevel(self::submission($record), $data['kyc_level'], $data['reason'], auth()->user()), __('kyc_actions.kycSetLevel.done')));
    }

    public static function assessRisk(): Action
    {
        $p = 'kyc.review';

        return WorkflowAction::make('kycAssessRisk', $p, self::LANG)->icon('lucide-gauge')
            ->visible(fn (KycSubmission $record) => in_array($record->status, ['SUBMITTED', 'REVIEWING'], true))
            ->schema([
                TextInput::make('country_code')->label(__('kyc_actions.fields.country_code'))->length(2),
                TagsInput::make('product_codes')->label(__('kyc_actions.fields.product_codes'))->nestedRecursiveRules(['string', 'max:64']),
                TextInput::make('channel')->label(__('kyc_actions.fields.channel'))->maxLength(40),
                Select::make('customer_type')->label(__('kyc_actions.fields.customer_type'))->options(self::codes(['INDIVIDUAL', 'CORPORATE'], 'kind')),
                TagsInput::make('declared_factors')->label(__('kyc_actions.fields.declared_factors'))->nestedRecursiveRules(['string', 'max:64']),
                Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(500),
            ])
            ->action(function (Action $action, KycSubmission $record, array $data) use ($p) {
                $products = array_values($data['product_codes'] ?? []);
                $factors = array_values($data['declared_factors'] ?? []);
                $inputs = array_filter([
                    'country_code' => filled($data['country_code'] ?? null) ? strtoupper($data['country_code']) : null,
                    'product_codes' => $products ?: null,
                    'channel' => filled($data['channel'] ?? null) ? strtoupper($data['channel']) : null,
                    'customer_type' => filled($data['customer_type'] ?? null) ? $data['customer_type'] : null,
                    'declared_factors' => $factors ?: null,
                ], fn ($v) => $v !== null);

                return WorkflowAction::run($action, $p, function () use ($record, $inputs, $data, $products, $factors) {
                    if (count($products) > 50 || count($factors) > 20) {
                        throw new ApiProblemException('VALIDATION_FAILED', 422, __('kyc_actions.kycAssessRisk.too_many'));
                    }

                    return app(KycService::class)->assessRisk(self::submission($record), $inputs, $data['reason'], auth()->user());
                }, __('kyc_actions.kycAssessRisk.done'));
            });
    }

    public static function recordScreening(): Action
    {
        $p = 'kyc.screen';

        return WorkflowAction::make('kycRecordScreening', $p, self::LANG)->icon('lucide-scan-search')
            ->visible(fn (KycSubmission $record) => in_array($record->status, ['SUBMITTED', 'REVIEWING'], true)
                || ($record->status === 'APPROVED' && self::checks($record)->where('status', 'PENDING')->where('screening_round', '>', 1)->exists()))
            ->schema(fn (KycSubmission $record) => [
                Select::make('check_id')->label(__('kyc_actions.fields.check'))->required()
                    ->options(fn () => self::checks($record)->orderBy('created_at')->get()
                        ->mapWithKeys(fn (ScreeningCheck $c) => [$c->id => $c->check_type.' #'.((int) ($c->screening_round ?? 1)).' ('.self::code('check_status', (string) $c->status).')'])->all()),
                Select::make('status')->label(__('kyc_actions.fields.result'))->required()->options(self::codes(['CLEAR', 'POSSIBLE_MATCH', 'CONFIRMED_MATCH'], 'check_status')),
                TextInput::make('list_reference')->label(__('kyc_actions.fields.list_reference'))->required()->maxLength(255),
                Textarea::make('notes')->label(__('kyc_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $s = self::submission($record);
                $c = ScreeningCheck::where('tenant_id', $s->tenant_id)->whereKey($data['check_id'])->first()
                    ?? throw new ApiProblemException('SCREENING_NOT_FOUND', 404, 'Screening check not found.');

                return app(KycService::class)->recordScreening($s, $c, ['status' => $data['status'], 'list_reference' => $data['list_reference'],
                    'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null], auth()->user());
            }, __('kyc_actions.kycRecordScreening.done')));
    }

    public static function requestInformation(): Action
    {
        $p = 'kyc.review';

        return WorkflowAction::make('kycRequestInformation', $p, self::LANG)->icon('lucide-message-circle-question')
            ->visible(fn (KycSubmission $record) => in_array($record->status, ['SUBMITTED', 'REVIEWING', 'PENDING_APPROVAL'], true))
            ->schema([Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->requestInformation(self::submission($record), $data['reason'], auth()->user()), __('kyc_actions.kycRequestInformation.done')));
    }

    public static function recommend(): Action
    {
        $p = 'kyc.review';

        return WorkflowAction::make('kycRecommend', $p, self::LANG)->icon('lucide-thumbs-up')
            ->visible(fn (KycSubmission $record) => $record->status === 'REVIEWING')
            ->schema([
                Select::make('outcome')->label(__('kyc_actions.fields.outcome'))->required()->options(self::codes(['APPROVE', 'REJECT'], 'outcome')),
                Textarea::make('rationale')->label(__('kyc_actions.fields.rationale'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->recommend(self::submission($record), $data['outcome'], $data['rationale'], auth()->user()), __('kyc_actions.kycRecommend.done')));
    }

    public static function decide(): Action
    {
        $p = 'kyc.decide';

        return WorkflowAction::make('kycDecide', $p, self::LANG)->icon('lucide-gavel')->color('success')
            ->visible(fn (KycSubmission $record) => $record->status === 'PENDING_APPROVAL')
            ->modalDescription(fn (KycSubmission $record) => __('kyc_actions.kycDecide.help', ['outcome' => self::code('outcome', (string) $record->recommended_outcome)]))
            ->schema([
                Toggle::make('confirm')->label(__('kyc_actions.fields.confirm'))->helperText(__('kyc_actions.fields.confirm_help'))->default(true),
                Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->decide(self::submission($record), (bool) ($data['confirm'] ?? false), $data['reason'], auth()->user()), __('kyc_actions.kycDecide.done')));
    }

    public static function rescreen(): Action
    {
        $p = 'kyc.screen';

        return WorkflowAction::make('kycRescreen', $p, self::LANG)->icon('lucide-refresh-cw')
            ->visible(fn (KycSubmission $record) => $record->status === 'APPROVED')
            ->schema([Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(500)])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->rescreen(self::submission($record), 'MANUAL_RESCREEN', $data['reason'], auth()->user()), __('kyc_actions.kycRescreen.done')));
    }

    public static function remediate(): Action
    {
        $p = 'kyc.manage';

        return WorkflowAction::make('kycRemediate', $p, self::LANG)->icon('lucide-rotate-ccw')->color('warning')
            ->visible(fn (KycSubmission $record) => in_array($record->status, ['APPROVED', 'EXPIRED', 'REJECTED'], true) && ! $record->superseded_by_submission_id)
            ->schema([Textarea::make('reason')->label(__('kyc_actions.fields.reason'))->required()->maxLength(500)])
            ->action(fn (Action $action, KycSubmission $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->remediate(self::submission($record), $data['reason'], auth()->user()), __('kyc_actions.kycRemediate.done')));
    }

    /** The KYC subject's documents in this tenant (the service / controller re-check tenant ownership). */
    private static function documents(KycSubmission $record): array
    {
        return Document::where('tenant_id', $record->tenant_id)->where('party_id', $record->party_id)->latest('created_at')->limit(100)->get()
            ->mapWithKeys(fn (Document $d) => [$d->id => trim(($d->title ?: $d->document_type_code ?: $d->category).' · '.$d->created_at?->format('Y-m-d'))])->all();
    }

    private static function checks(KycSubmission $record)
    {
        return ScreeningCheck::where('tenant_id', $record->tenant_id)->where('subject_type', 'kyc_submission')->where('subject_id', $record->id);
    }

    /** @param  list<string>  $values */
    private static function codes(array $values, string $group): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => self::code($group, $v)])->all();
    }

    private static function code(string $group, string $value): string
    {
        return WorkflowAction::optional("kyc_actions.codes.{$group}.{$value}") ?? $value;
    }

    private static function submission(KycSubmission $record): KycSubmission
    {
        return KycSubmission::where('tenant_id', app(TenantContext::class)->id())->findOrFail($record->id);
    }
}
