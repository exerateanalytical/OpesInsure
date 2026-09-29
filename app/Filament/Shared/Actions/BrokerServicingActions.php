<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\Fnol\FnolService;
use App\Application\Identity\InvitationService;
use App\Application\Policies\Endorsements\ServiceRequestIntake;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Owner decision 2026-09-29 (portals writable, D4 lifted): broker portal (/broker) servicing & operations actions.
 * Each one calls the SAME service as the matching API route, behind the same gate; the record must be the caller's
 * own (PortalScope::isOwnRecord — tenant + broker book; docs/spec/PORTAL_WRITE_RULES.md). Labels: broker_portal_service.
 *   serviceRequest  POST mobile/policies/{p}/service-requests      (book access)          ServiceRequestIntake::submit
 *   assistedClaim   POST mobile/partner/broker/claims              broker.claims.file     FnolService::submitForBrokerClient
 *   inviteStaff     POST mobile/partner/broker/staff/invitations   BROKER_ADMIN only      InvitationService::issue (PlatformAuthority: no escalation)
 */
final class BrokerServicingActions
{
    public const LANG = 'broker_portal_service';

    /** Roles a broker administrator may invite from the portal (PlatformAuthority::assertMayGrant re-checks each). */
    public const INVITABLE_ROLES = ['BROKER_STAFF', 'BROKER_SUPERVISOR'];

    /** Policy detail: servicing request (endorsement, certificate / document re-issue, cancellation review ...). */
    public static function serviceRequest(): Action
    {
        $p = 'broker.portal.read';

        return WorkflowAction::make('serviceRequest', $p, self::LANG)->icon('lucide-file-pen-line')
            ->visible(fn (Policy $record) => in_array($record->status, ['ACTIVE', 'SUSPENDED'], true))
            ->schema([
                Select::make('type')->label(__(self::LANG.'.fields.type'))->required()
                    ->options(collect(ServiceRequestIntake::TYPES)->mapWithKeys(fn ($t) => [$t => __(self::LANG.'.types.'.$t)])->all()),
                Textarea::make('reason')->label(__(self::LANG.'.fields.reason'))->required()->minLength(5)->maxLength(2000),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                abort_unless(PortalScope::isOwnRecord($record), 404);

                return app(ServiceRequestIntake::class)->submit($record, $data['type'], $data['reason'], auth()->user(), null, 'BROKER_WEB');
            }, __(self::LANG.'.serviceRequest.done')));
    }

    /** Claims list: declare a claim (FNOL) on behalf of a customer of the broker's book. */
    public static function assistedClaim(): Action
    {
        $p = 'broker.claims.file';

        return WorkflowAction::make('assistedClaim', $p, self::LANG)->icon('lucide-siren')
            ->schema([
                Select::make('policy_id')->label(__(self::LANG.'.fields.policy'))->required()->searchable()
                    ->options(fn () => PortalScope::narrowTable(Policy::query()->where('tenant_id', app(TenantContext::class)->id()), 'policies')
                        ->where('status', 'ACTIVE')->with('party')->limit(200)->get()
                        ->mapWithKeys(fn (Policy $x) => [$x->id => trim(($x->policy_number ?? $x->id).' · '.($x->party?->display_name ?? ''), ' ·')])->all()),
                DateTimePicker::make('loss_occurred_at')->label(__(self::LANG.'.fields.loss_occurred_at'))->required()->maxDate(now()),
                TextInput::make('loss_location')->label(__(self::LANG.'.fields.loss_location'))->maxLength(255),
                TextInput::make('estimated_loss_minor')->label(__(self::LANG.'.fields.estimated_loss'))->integer()->minValue(0),
                Textarea::make('description')->label(__(self::LANG.'.fields.description'))->required()->minLength(5)->maxLength(4000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $tenant = app(TenantContext::class)->id();
                $policy = Policy::query()->where('tenant_id', $tenant)->find($data['policy_id']);
                abort_unless($policy !== null && PortalScope::isOwnRecord($policy), 404);

                return app(FnolService::class)->submitForBrokerClient($tenant, [
                    'policy_id' => $policy->id, 'claimant_party_id' => $policy->party_id,
                    'loss_occurred_at' => $data['loss_occurred_at'], 'loss_details' => ['description' => $data['description']],
                    'loss_location' => filled($data['loss_location'] ?? null) ? $data['loss_location'] : null,
                    'estimated_loss_minor' => filled($data['estimated_loss_minor'] ?? null) ? (int) $data['estimated_loss_minor'] : null,
                    'idempotency_key' => 'broker-web-'.Str::uuid(),
                ], auth()->user());
            }, __(self::LANG.'.assistedClaim.done')));
    }

    /** Staff list: invite a colleague (broker administrators only, as the API; no role escalation). */
    public static function inviteStaff(): Action
    {
        return WorkflowAction::make('inviteStaff', null, self::LANG)->icon('lucide-user-plus')
            ->visible(fn () => self::isBrokerAdmin())
            ->schema([
                TextInput::make('recipient_email')->label(__(self::LANG.'.fields.recipient_email'))->email()->requiredWithout('recipient_phone_e164'),
                TextInput::make('recipient_phone_e164')->label(__(self::LANG.'.fields.recipient_phone'))->maxLength(20)->requiredWithout('recipient_email'),
                Select::make('role_code')->label(__(self::LANG.'.fields.role'))->required()->default('BROKER_STAFF')
                    ->options(collect(self::INVITABLE_ROLES)->mapWithKeys(fn ($r) => [$r => __('partner_portal.roles.'.$r) === 'partner_portal.roles.'.$r ? $r : __('partner_portal.roles.'.$r)])->all()),
            ])
            ->action(function (Action $action, array $data) {
                $result = WorkflowAction::run($action, null, function () use ($data) {
                    abort_unless(self::isBrokerAdmin(), 403);
                    abort_unless(in_array($data['role_code'], self::INVITABLE_ROLES, true), 422);
                    $email = filled($data['recipient_email'] ?? null) ? $data['recipient_email'] : null;
                    $phone = $email === null && filled($data['recipient_phone_e164'] ?? null) ? $data['recipient_phone_e164'] : null;

                    return app(InvitationService::class)->issue(Tenant::findOrFail(app(TenantContext::class)->id()), auth()->user(), $email, $phone, $data['role_code']);
                }, __(self::LANG.'.inviteStaff.done'));
                if (is_array($result)) {
                    Notification::make()->warning()->persistent()->title(__(self::LANG.'.inviteStaff.code'))->body($result['token'])->send();
                }
            });
    }

    /** Same test as PartnerBrokerWorkspaceController::isAdmin: an ACTIVE BROKER_ADMIN membership in the portal tenant. */
    public static function isBrokerAdmin(): bool
    {
        $user = auth()->user();
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $user !== null && $tenant !== null
            && TenantMembership::where(['tenant_id' => $tenant, 'user_id' => $user->id, 'role_code' => 'BROKER_ADMIN', 'status' => 'ACTIVE'])->exists();
    }
}
