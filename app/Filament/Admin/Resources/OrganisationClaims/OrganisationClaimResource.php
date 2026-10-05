<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OrganisationClaims;

use App\Application\Partners\Onboarding\IntakeReview;
use App\Application\Partners\Onboarding\OrganisationClaimService;
use App\Filament\Admin\Concerns\IntakeReviewActions;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\OrganisationClaim;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Claim this organisation" review (/admin, platform tenant, tenant.manage + identity.invite). Approval links the
 * official-register insurer/broker to a new or existing tenant and invites the claimant (maker-checker).
 */
final class OrganisationClaimResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = OrganisationClaim::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-flag';

    protected static ?string $slug = 'organisation-claims';

    protected static ?int $navigationSort = 14;

    public static function getNavigationLabel(): string
    {
        return __('org_claim.admin.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('bulk_onboarding.group');
    }

    public static function getModelLabel(): string
    {
        return __('org_claim.admin.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org_claim.admin.models');
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

    public static function getEloquentQuery(): Builder
    {
        return OrganisationClaim::query()->where('status', '!=', 'UNVERIFIED');
    }

    private static function tr(string $key, array $r = []): string
    {
        return __('org_claim.admin.'.$key, $r);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('reference')->label(self::tr('columns.reference'))->searchable(),
            Tables\Columns\TextColumn::make('institution_name')->label(self::tr('columns.institution'))->searchable()->wrap()
                ->description(fn (OrganisationClaim $c) => __('partner_apply.types.'.$c->institution_type)),
            Tables\Columns\TextColumn::make('claimant_name')->label(self::tr('columns.claimant'))->description(fn (OrganisationClaim $c) => $c->claimant_position.' · '.$c->claimant_email),
            Tables\Columns\TextColumn::make('verification_mode')->label(self::tr('columns.mode'))->formatStateUsing(fn ($s) => self::tr('modes.'.$s)),
            Tables\Columns\IconColumn::make('is_dispute')->label(self::tr('columns.dispute'))->boolean(),
            \App\Filament\Shared\Columns::status('status')->label(self::tr('columns.status')),
            \App\Filament\Shared\Columns::date('created_at')->label(self::tr('columns.submitted'))->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label(self::tr('columns.status'))->options(__('org_claim.statuses')),
        ])->recordActions([
            ...IntakeReviewActions::common(fn (OrganisationClaim $c) => self::details($c)),
            Actions\Action::make('approve')->label(__('partner_apply.admin.actions.approve'))->icon('lucide-check')->color('success')->requiresConfirmation()
                ->visible(fn (OrganisationClaim $c) => ! $c->is_dispute && IntakeReviewActions::canDecide($c) && $c->recommendation === 'APPROVE')
                ->schema(fn (OrganisationClaim $c) => [
                    Forms\Components\Select::make('tenant_id')->label(self::tr('fields.tenant'))->searchable()
                        ->options(fn () => Tenant::query()->whereIn('type', $c->institution_type === 'INSURER' ? ['CARRIER', 'INSURER'] : ['BROKER'])
                            ->orderBy('legal_name')->limit(200)->pluck('legal_name', 'id')->all()),
                    Forms\Components\Textarea::make('note')->label(__('partner_apply.admin.fields.note'))->maxLength(1000),
                ])
                ->action(function (OrganisationClaim $c, array $data) {
                    if (ServiceValidation::run(fn () => app(OrganisationClaimService::class)->approve($c, auth()->user(), $data['tenant_id'] ?? null, $data['note'] ?? null))) {
                        Notification::make()->success()->title(__('partner_apply.admin.notify.approved'))->send();
                    }
                }),
        ])->emptyStateHeading(self::tr('empty'))->emptyStateIcon('lucide-flag');
    }

    /** @return array<string, ?string> */
    private static function details(OrganisationClaim $c): array
    {
        $others = OrganisationClaim::where('institution_key', $c->institution_key)->whereKeyNot($c->id)->where('status', '!=', 'UNVERIFIED')
            ->get(['reference', 'status', 'is_dispute'])->map(fn ($o) => $o->reference.' '.$o->status.($o->is_dispute ? ' (dispute)' : ''))->implode("\n");

        return [
            self::tr('columns.reference') => $c->reference,
            self::tr('columns.institution') => $c->institution_name.' — '.__('partner_apply.types.'.$c->institution_type).' ('.$c->institution_key.')',
            self::tr('columns.claimant') => $c->claimant_name.' · '.$c->claimant_position.' · '.$c->claimant_email.' · '.($c->claimant_phone ?? '—'),
            self::tr('columns.mode') => self::tr('modes.'.$c->verification_mode).($c->verified_at ? ' ✓ '.$c->verified_at->toDateTimeString().' → '.$c->official_destination_masked : ''),
            __('partner_apply.admin.details.flags') => $others ?: null,
            __('partner_apply.admin.columns.reviewer') => $c->reviewed_by ? (User::find($c->reviewed_by)?->full_name.' — '.($c->recommendation ?? '…').($c->review_note ? ': '.$c->review_note : '')) : null,
            __('partner_apply.admin.details.response') => $c->applicant_response,
            __('partner_apply.admin.details.result') => $c->result && isset($c->result['tenant_id']) ? json_encode($c->result, JSON_UNESCAPED_SLASHES) : null,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOrganisationClaims::route('/')];
    }
}
