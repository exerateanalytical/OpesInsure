<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InsuranceProducts\Pages;

use App\Application\Catalogue\Governance\ProductGovernanceService;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\InsuranceProducts\InsuranceProductResource;
use App\Models\InsuranceProduct;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * REQ-PRD-010 — INS-PRODUCT-VERSION product builder screen: tabs from ProductVersionTabs (shared RecordShell)
 * and governance / sandbox actions. Every action goes through the same services as the API
 * (ProductGovernanceService, ProductSandbox); each is permission- and stage-aware (SSR §25).
 */
final class ViewInsuranceProduct extends ViewRecord
{
    protected static string $resource = InsuranceProductResource::class;

    protected function getHeaderActions(): array
    {
        /** @var InsuranceProduct $v */
        $v = $this->record;
        $gov = app(ProductGovernanceService::class);
        $stage = fn () => $gov->state($this->record)->stage;
        $can = fn (string $p) => (bool) auth()->user()?->hasPermission($p);
        $advancePermission = ['DRAFT' => 'catalogue.manage', 'CONFIGURATION' => 'catalogue.manage', 'TECHNICAL_REVIEW' => 'catalogue.review',
            'COMPLIANCE_REVIEW' => 'catalogue.review', 'BUSINESS_APPROVAL' => 'catalogue.publish'];

        return [
            Action::make('advance')->label(__('product_builder.actions.advance'))->icon('heroicon-o-arrow-right-circle')
                ->visible(fn () => isset($advancePermission[$stage()]) && $can($advancePermission[$stage()]))
                ->schema([Textarea::make('notes')->label(__('product_builder.actions.notes'))->maxLength(2000)])
                ->action(fn (array $d) => $this->done(ServiceValidation::run(fn () => $gov->advance($this->record->refresh(), auth()->user(), (string) ($d['notes'] ?? ''))), 'advanced')),
            Action::make('publish')->label(__('product_builder.actions.publish'))->icon('heroicon-o-rocket-launch')->color('success')
                ->visible(fn () => $stage() === 'READY' && $can('catalogue.publish'))
                ->schema([DateTimePicker::make('publish_at')->label(__('product_builder.actions.publish_at')), Textarea::make('reason')->label(__('product_builder.actions.reason'))->maxLength(2000)])
                ->action(fn (array $d) => $this->done(ServiceValidation::run(fn () => $gov->publish($this->record->refresh(), auth()->user(), (string) ($d['reason'] ?? ''), $d['publish_at'] ?? null)), 'advanced')),
            Action::make('reject')->label(__('product_builder.actions.reject'))->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                ->visible(fn () => in_array($stage(), [...ProductGovernanceService::REVIEW_STAGES, 'READY'], true) && ($can('catalogue.review') || $can('catalogue.publish')))
                ->schema([Textarea::make('reason')->label(__('product_builder.actions.reason'))->required()->minLength(10)->maxLength(2000)])
                ->action(fn (array $d) => $this->done(ServiceValidation::run(fn () => $gov->reject($this->record->refresh(), auth()->user(), $d['reason'])), 'advanced')),
            Action::make('addCase')->label(__('product_builder.actions.add_case'))->icon('heroicon-o-beaker')
                ->visible(fn () => $can('catalogue.test'))
                ->schema([
                    TextInput::make('code')->label(__('product_builder.actions.code'))->required()->maxLength(64)->regex('/^[A-Z0-9_\-]+$/'),
                    TextInput::make('name')->label(__('product_builder.actions.name'))->required()->maxLength(191),
                    KeyValue::make('facts')->label(__('product_builder.actions.facts'))->required(),
                    Select::make('expected_eligibility')->label(__('product_builder.actions.expected_eligibility'))->native(false)
                        ->options(array_combine($o = ['ELIGIBLE', 'INELIGIBLE', 'CONDITIONAL', 'REFER_TO_UNDERWRITING', 'MORE_INFORMATION_REQUIRED'], $o)),
                    TextInput::make('expected_total')->label(__('product_builder.actions.expected_total'))->integer()->minValue(0),
                ])
                ->action(function (array $d) {
                    $facts = array_map(fn ($x) => is_numeric($x) ? $x + 0 : $x, (array) $d['facts']);
                    $expected = array_filter(['eligibility' => $d['expected_eligibility'] ?? null, 'premium_total_minor' => isset($d['expected_total']) && $d['expected_total'] !== '' ? (int) $d['expected_total'] : null], fn ($x) => $x !== null);
                    $this->done(ServiceValidation::run(fn () => app(ProductSandbox::class)->addCase($this->record, ['code' => $d['code'], 'name' => $d['name'], 'facts' => $facts, 'expected' => $expected], auth()->user())), 'advanced');
                }),
            Action::make('runTests')->label(__('product_builder.actions.run_tests'))->icon('heroicon-o-play')
                ->visible(fn () => $can('catalogue.test'))
                ->action(fn () => $this->done(ServiceValidation::run(fn () => app(ProductSandbox::class)->runPack($this->record, auth()->user())), 'tests_done')),
            Action::make('attributes')->label(__('product_builder.actions.attributes'))->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn () => $can('catalogue.manage'))
                ->fillForm(fn () => $gov->state($this->record)->only(['owner_user_id', 'target_market', 'prohibited_market', 'next_review_date']))
                ->schema([
                    Select::make('owner_user_id')->label(__('product_builder.actions.owner'))->searchable()
                        ->getSearchResultsUsing(fn (string $q) => \App\Models\User::where('full_name', 'ilike', "%{$q}%")->limit(20)->pluck('full_name', 'id')->all())
                        ->getOptionLabelUsing(fn ($id) => \App\Models\User::find($id)?->full_name),
                    TagsInput::make('target_market')->label(__('product_builder.governance.target_market')),
                    TagsInput::make('prohibited_market')->label(__('product_builder.governance.prohibited_market')),
                    DatePicker::make('next_review_date')->label(__('product_builder.governance.review_date')),
                ])
                ->action(fn (array $d) => $this->done(ServiceValidation::run(fn () => $gov->updateAttributes($this->record, $d, auth()->user())), 'advanced')),
        ];
    }

    private function done(mixed $result, string $message): void
    {
        if ($result === null) {
            return;
        }
        $this->record->refresh();
        Notification::make()->success()->title(__('product_builder.actions.'.$message))->send();
    }
}
