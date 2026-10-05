<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerApplications;

use App\Application\Partners\Onboarding\IntakeReview;
use App\Application\Partners\Onboarding\PartnerApplicationService;
use App\Filament\Admin\Concerns\IntakeReviewActions;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\PartnerApplication;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Partner self-service applications (/admin, platform tenant, tenant.manage + identity.invite). Reviewer records a
 * recommendation; a different admin approves (organisation provisioned + invitation) or rejects.
 */
final class PartnerApplicationResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = PartnerApplication::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-handshake';

    protected static ?string $slug = 'partner-applications';

    protected static ?int $navigationSort = 13;

    public static function getNavigationLabel(): string
    {
        return __('partner_apply.admin.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('bulk_onboarding.group');
    }

    public static function getModelLabel(): string
    {
        return __('partner_apply.admin.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner_apply.admin.models');
    }

    public static function canViewAny(): bool
    {
        return app(IntakeReview::class)->allows(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Platform-wide queue: unverified drafts (no confirmed contact yet) are not shown. */
    public static function getEloquentQuery(): Builder
    {
        return PartnerApplication::query()->where('status', '!=', 'UNVERIFIED');
    }

    private static function tr(string $key, array $r = []): string
    {
        return __('partner_apply.admin.'.$key, $r);
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(PartnerApplicationService::class);

        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('reference')->label(self::tr('columns.reference'))->searchable(),
            Tables\Columns\TextColumn::make('type')->label(self::tr('columns.type'))->formatStateUsing(fn ($s) => __('partner_apply.types.'.$s)),
            Tables\Columns\TextColumn::make('legal_name')->label(self::tr('columns.name'))->searchable()->wrap(),
            Tables\Columns\TextColumn::make('applicant_name')->label(self::tr('columns.applicant'))->description(fn (PartnerApplication $a) => $a->applicant_email),
            \App\Filament\Shared\Columns::status('status')->label(self::tr('columns.status')),
            Tables\Columns\TextColumn::make('recommendation')->label(self::tr('columns.recommendation'))->placeholder('—'),
            Tables\Columns\TextColumn::make('flags')->label(self::tr('columns.flags'))->state(fn (PartnerApplication $a) => count($a->duplicate_flags ?? []) ?: null)->placeholder('—'),
            \App\Filament\Shared\Columns::date('created_at')->label(self::tr('columns.submitted'))->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label(self::tr('columns.status'))->options(__('partner_apply.statuses')),
            Tables\Filters\SelectFilter::make('type')->label(self::tr('columns.type'))->options(__('partner_apply.types')),
        ])->recordActions([
            ...IntakeReviewActions::common(fn (PartnerApplication $a) => self::details($a)),
            Actions\Action::make('confirmBrokerage')->label(self::tr('actions.confirm_brokerage'))->icon('lucide-badge-check')->color('gray')
                ->visible(fn (PartnerApplication $a) => $a->type === 'AGENT' && $a->brokerage_tenant_id && ! $a->brokerage_confirmed_at && ! $a->brokerage_declined_at && in_array($a->status, IntakeReview::OPEN, true))
                ->schema([Forms\Components\Textarea::make('note')->label(self::tr('fields.note'))->required()->minLength(5)->maxLength(1000)])
                ->action(fn (PartnerApplication $a, array $data) => $service()->brokerageAnswer($a, true, auth()->user(), $data['note'])),
            Actions\Action::make('approve')->label(self::tr('actions.approve'))->icon('lucide-check')->color('success')->requiresConfirmation()
                ->visible(fn (PartnerApplication $a) => IntakeReviewActions::canDecide($a) && $a->recommendation === 'APPROVE')
                ->schema([Forms\Components\Textarea::make('note')->label(self::tr('fields.note'))->maxLength(1000)])
                ->action(function (PartnerApplication $a, array $data) use ($service) {
                    if (ServiceValidation::run(fn () => $service()->approve($a, auth()->user(), $data['note'] ?? null))) {
                        Notification::make()->success()->title(self::tr('notify.approved'))->send();
                    }
                }),
        ])->emptyStateHeading(self::tr('empty'))->emptyStateIcon('lucide-handshake');
    }

    /** @return array<string, ?string> */
    private static function details(PartnerApplication $a): array
    {
        $d = fn (string $k) => self::tr('details.'.$k);

        return [
            self::tr('columns.reference') => $a->reference.' — '.__('partner_apply.types.'.$a->type),
            self::tr('columns.name') => trim($a->legal_name.($a->trade_name ? ' ('.$a->trade_name.')' : '')),
            $d('identifiers') => 'RCCM '.($a->rccm ?? '—').' · NIU '.($a->niu ?? '—'),
            $d('licence') => $a->licence_number ? $a->licence_number.' → '.$a->licence_expires_on?->toDateString() : null,
            $d('brokerage') => $a->type === 'AGENT' ? ($a->brokerage?->legal_name ?? $d('independent')).($a->brokerage_confirmed_at ? ' ✓ '.$a->brokerage_confirmed_at->toDateString() : ($a->brokerage_declined_at ? ' ✗' : '')) : null,
            __('partner_apply.fields.city') => trim($a->city.' '.($a->address ?? '')),
            $d('contact') => $a->applicant_name.' · '.$a->applicant_email.' · '.($a->applicant_phone ?? '—'),
            $d('verified') => $a->verified_at ? $a->verified_at->toDateTimeString().' ('.$a->verification_channel.')' : null,
            $d('flags') => implode("\n", $a->duplicate_flags ?? []) ?: null,
            self::tr('columns.reviewer') => $a->reviewed_by ? (User::find($a->reviewed_by)?->full_name.' — '.($a->recommendation ?? '…').($a->review_note ? ': '.$a->review_note : '')) : null,
            $d('response') => $a->applicant_response,
            $d('result') => $a->result && isset($a->result['tenant_id']) ? json_encode($a->result, JSON_UNESCAPED_SLASHES) : null,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPartnerApplications::route('/')];
    }
}
