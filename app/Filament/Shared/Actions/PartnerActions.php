<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Partners\PartnerStatusService;
use App\Application\Partners\Setup\Models\PartnerSetup;
use App\Application\Partners\Setup\PartnerSetupService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Partner detail-page actions. Same services / permissions as:
 *   openSetup      POST partners/{p}/setup                         partner_setup.manage             PartnerSetupService::open
 *   attestItem     PUT  partners/{p}/setup/checklist/{item}        partner_setup.manage             PartnerSetupService::attest
 *   changeStatus   POST partners/{p}/status                        partners.manage                  PartnerStatusService::transition (activate / suspend / reject / pending)
 *   agreements     GET  carrier-broker-agreements?partner_id=      distribution.agreements.view     read-only
 * Partners resolve as the API does: own tenant, or a platform/compliance administrator.
 */
final class PartnerActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::openSetup(), self::attestItem(), self::changeStatus(), self::agreements()])
            ->label(__('workflow_actions.partner_group'))->icon('lucide-zap')->button();
    }

    public static function openSetup(): Action
    {
        $p = 'partner_setup.manage';

        return WorkflowAction::make('partnerOpenSetup', $p)->icon('lucide-clipboard-list')
            ->visible(fn (Partner $record) => in_array($record->type, PartnerSetupService::PARTNER_TYPES, true) && self::setup($record) === null)
            ->schema([Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000)])
            ->action(fn (Action $action, Partner $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartnerSetupService::class)->open(self::scoped($record), auth()->user(), $data['notes'] ?? null)));
    }

    public static function attestItem(): Action
    {
        $p = 'partner_setup.manage';

        return WorkflowAction::make('partnerAttestItem', $p)->icon('lucide-circle-check')
            ->visible(fn (Partner $record) => self::setup($record) !== null)
            ->schema([
                Select::make('item')->label(__('workflow_actions.fields.checklist_item'))->required()
                    ->options(fn (Partner $record) => collect(app(PartnerSetupService::class)->items(self::setup($record)))->mapWithKeys(fn ($i) => [$i['code'] => $i['label']])->all()),
                Select::make('status')->label(__('workflow_actions.fields.status'))->required()->options(WorkflowAction::options(['COMPLETE', 'NOT_APPLICABLE', 'PENDING'], 'attest')),
                TextInput::make('evidence_reference')->label(__('workflow_actions.fields.evidence_reference'))->maxLength(255),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Partner $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartnerSetupService::class)->attest(
                self::setup(self::scoped($record)) ?? abort(404), strtoupper($data['item']), $data['status'], $data['evidence_reference'] ?? null, $data['notes'] ?? null, auth()->user())));
    }

    public static function changeStatus(): Action
    {
        $p = 'partners.manage';

        return WorkflowAction::make('partnerChangeStatus', $p)->icon('lucide-arrow-left-right')->requiresConfirmation()
            ->schema([
                Select::make('status')->label(__('workflow_actions.fields.status'))->required()->options(WorkflowAction::options(['ACTIVE', 'SUSPENDED', 'REJECTED', 'PENDING'], 'partner_status')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->required()->minLength(5)->maxLength(2000),
            ])
            ->action(fn (Action $action, Partner $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PartnerStatusService::class)->transition(self::scoped($record), $data['status'], $data['notes'], auth()->user())));
    }

    public static function agreements(): Action
    {
        $p = 'distribution.agreements.view';

        return WorkflowAction::make('partnerAgreements', $p)->icon('lucide-file-text')
            ->modalSubmitAction(false)->modalCancelActionLabel(__('workflow_actions.close'))
            ->modalContent(function (Partner $record) {
                $rows = DB::table('carrier_broker_agreements as a')->leftJoin('carriers as c', 'c.id', '=', 'a.carrier_id')->leftJoin('parties as cp', 'cp.id', '=', 'c.party_id')
                    ->where('a.partner_id', self::scoped($record)->id)->orderByDesc('a.effective_from')->limit(200)
                    ->get(['a.agreement_number', 'a.status', 'a.effective_from', 'a.effective_until', 'cp.display_name as carrier']);
                if ($rows->isEmpty()) {
                    return new HtmlString('<p>'.e(__('workflow_actions.partnerAgreements.none')).'</p>');
                }
                $h = collect(['carrier', 'agreement_number', 'status', 'effective_from', 'effective_until'])->map(fn ($c) => '<th class="px-2 py-1 text-start">'.e(__("workflow_actions.fields.{$c}")).'</th>')->implode('');
                $b = $rows->map(fn ($r) => '<tr>'.collect([$r->carrier, $r->agreement_number, $r->status, $r->effective_from, $r->effective_until ?? '—'])
                    ->map(fn ($v) => '<td class="px-2 py-1">'.e((string) $v).'</td>')->implode('').'</tr>')->implode('');

                return new HtmlString('<table class="w-full text-sm"><thead><tr>'.$h.'</tr></thead><tbody>'.$b.'</tbody></table>');
            })
            ->action(fn () => null);
    }

    private static function setup(Partner $partner): ?PartnerSetup
    {
        return PartnerSetup::where('partner_id', $partner->id)->first();
    }

    private static function scoped(Partner $p): Partner
    {
        $global = auth()->user()?->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
        abort_unless($global || $p->tenant_id === app(TenantContext::class)->id(), 404);

        return $p;
    }
}
