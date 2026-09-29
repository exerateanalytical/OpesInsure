<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Customers\Matching\PartyMatcher;
use App\Application\Customers\Matching\PartyMergeService;
use App\Application\PartnerWorkspace\AgentLeadService;
use App\Models\Parties\EntityMatchCandidate;
use App\Models\Parties\OwnershipInterest;
use App\Models\Parties\PartyMerge;
use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Application\Customers\Roles\PartyRoleService;
use App\Application\Kyc\KycService;
use App\Application\Privacy\ConsentService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\KycSubmission;
use App\Models\Parties\PartyRelationship;
use App\Models\Parties\PartyRole;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Party / customer detail-page actions (the record is a Party or a TenantCustomer). Same services / permissions as:
 *   kycOpen           POST kyc/parties/{p}/submissions                 kyc.manage                        KycService::draftFor
 *   kycSubmit         POST kyc/submissions/{s}/submit                  kyc.manage                        KycService::submit
 *   requestMerge      POST party-merges                                parties.merge.request             PartyMergeService::request
 *   recordConsent     POST consents                                    privacy.consent.manage            ConsentService::grant
 *   assignRole        POST parties/{p}/roles                           parties.roles.manage              PartyRoleService::assign
 *   endRole           POST party-roles/{r}/end                         parties.roles.manage              PartyRoleService::end
 *   linkRelationship  POST parties/{p}/relationships                   parties.relationships.manage      PartyRelationshipService::link
 *   endRelationship   POST party-relationships/{r}/end                 parties.relationships.manage      PartyRelationshipService::endRelationship
 * Parties are resolved as the API does: a customer of the current tenant, or any party for a platform/compliance administrator.
 */
final class PartyActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::kycOpen(), self::kycSubmit(), self::requestMerge(), self::recordConsent(), self::assignRole(), self::endRole(), self::linkRelationship(), self::endRelationship()])
            ->label(__('workflow_actions.party_group'))->icon('lucide-zap')->button();
    }

    /**
     * Golden-record stewardship (labels in party_actions). Same services / permissions as:
     *   matchScan       POST parties/{p}/match-scan                     parties.match.review          PartyMatcher::scan
     *   matchDismiss    POST party-match-candidates/{c}/dismiss         parties.match.review          PartyMatcher::dismiss
     *   addOwnership    POST parties/{p}/ownership                      parties.relationships.manage  PartyRelationshipService::addOwnership
     *   endOwnership    POST ownership-interests/{i}/end                parties.relationships.manage  PartyRelationshipService::endOwnership
     *   mergeDecision   POST party-merges/{m}/decision                  parties.merge.approve         PartyMergeService::approveMerge / rejectMerge
     *   mergeUnmerge    POST party-merges/{m}/unmerge                   parties.merge.approve         PartyMergeService::unmerge
     */
    public static function stewardshipGroup(): ActionGroup
    {
        return ActionGroup::make([self::matchScan(), self::matchDismiss(), self::addOwnership(), self::endOwnership(), self::mergeDecision(), self::mergeUnmerge()])
            ->label(__('party_actions.group'))->icon('lucide-fingerprint')->button();
    }

    public static function matchScan(): Action
    {
        $p = 'parties.match.review';

        return WorkflowAction::make('matchScan', $p, 'party_actions')->icon('lucide-scan-search')->requiresConfirmation()
            ->action(fn (Action $action, Model $record) => WorkflowAction::run($action, $p,
                fn () => app(PartyMatcher::class)->scan(self::party($record), self::tenant(), auth()->user()), __('party_actions.matchScan.done')));
    }

    public static function matchDismiss(): Action
    {
        $p = 'parties.match.review';
        $open = fn (Model $r) => EntityMatchCandidate::where('status', 'OPEN')->where(fn ($q) => $q->where('party_a_id', self::party($r)->id)->orWhere('party_b_id', self::party($r)->id));

        return WorkflowAction::make('matchDismiss', $p, 'party_actions')->icon('lucide-circle-slash')->requiresConfirmation()
            ->visible(fn (Model $record) => $open($record)->exists())
            ->schema([
                Select::make('candidate_id')->label(__('party_actions.fields.candidate'))->required()
                    ->options(fn (Model $record) => $open($record)->get()->mapWithKeys(fn ($c) => [$c->id => (Party::find($c->party_a_id === self::party($record)->id ? $c->party_b_id : $c->party_a_id)?->display_name ?? '?').' · '.$c->band.' · '.$c->score])),
                Textarea::make('note')->label(__('workflow_actions.fields.notes'))->required()->minLength(3)->maxLength(500),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, function () use ($open, $record, $data) {
                $c = $open($record)->findOrFail($data['candidate_id']);
                self::resolve((string) $c->party_a_id);
                self::resolve((string) $c->party_b_id);

                return app(PartyMatcher::class)->dismiss($c, (string) auth()->id(), $data['note']);
            }, __('party_actions.matchDismiss.done')));
    }

    public static function addOwnership(): Action
    {
        $p = 'parties.relationships.manage';

        return WorkflowAction::make('addOwnership', $p, 'party_actions')->icon('lucide-pie-chart')
            ->visible(fn (Model $record) => self::party($record)->type === 'ORGANIZATION')
            ->schema([
                Select::make('owner_party_id')->label(__('party_actions.fields.owner'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search, Model $record) => self::partySearch($search, self::party($record)->id))->getOptionLabelUsing(fn ($value) => Party::find($value)?->display_name),
                TextInput::make('percentage')->label(__('party_actions.fields.percentage'))->required()->numeric()->minValue(0.0001)->maxValue(100),
                Select::make('interest_type')->label(__('party_actions.fields.interest_type'))
                    ->options(collect(PartyRelationshipService::INTEREST_TYPES)->mapWithKeys(fn ($t) => [$t => __('party_actions.interest_types.'.$t)])->all()),
                DatePicker::make('valid_from')->label(__('workflow_actions.fields.valid_from')),
                TextInput::make('evidence_reference')->label(__('workflow_actions.fields.evidence_reference'))->maxLength(255),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartyRelationshipService::class)->addOwnership(
                self::resolve($data['owner_party_id']), self::party($record), array_filter(collect($data)->except('owner_party_id')->all(), fn ($v) => filled($v)), self::tenant(), auth()->id()),
                __('party_actions.addOwnership.done')));
    }

    public static function endOwnership(): Action
    {
        $p = 'parties.relationships.manage';
        $active = fn (Model $r) => OwnershipInterest::where('owned_party_id', self::party($r)->id)->where('status', 'ACTIVE');

        return WorkflowAction::make('endOwnership', $p, 'party_actions')->icon('lucide-scissors')->requiresConfirmation()
            ->visible(fn (Model $record) => $active($record)->exists())
            ->schema([
                Select::make('interest_id')->label(__('party_actions.fields.interest'))->required()
                    ->options(fn (Model $record) => $active($record)->get()->mapWithKeys(fn ($i) => [$i->id => (Party::find($i->owner_party_id)?->display_name ?? '?').' · '.$i->interest_type.' · '.(float) $i->percentage.' %'])),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(3)->maxLength(500),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PartyRelationshipService::class)->endOwnership($active($record)->findOrFail($data['interest_id']), $data['reason']), __('party_actions.endOwnership.done')));
    }

    public static function mergeDecision(): Action
    {
        $p = 'parties.merge.approve';
        $pending = fn (Model $r) => self::merges($r, 'PENDING');

        return WorkflowAction::make('mergeDecision', $p, 'party_actions')->icon('lucide-git-merge')->requiresConfirmation()
            ->visible(fn (Model $record) => $pending($record)->exists())
            ->schema([
                Select::make('merge_id')->label(__('party_actions.fields.merge'))->required()->options(fn (Model $record) => self::mergeOptions($pending($record))),
                Select::make('decision')->label(__('party_actions.fields.decision'))->required()->live()
                    ->options(['APPROVED' => __('party_actions.decisions.APPROVED'), 'REJECTED' => __('party_actions.decisions.REJECTED')]),
                Textarea::make('note')->label(__('workflow_actions.fields.notes'))->maxLength(500)->required(fn ($get) => $get('decision') === 'REJECTED'),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, function () use ($pending, $record, $data) {
                $m = $pending($record)->findOrFail($data['merge_id']);
                self::resolve((string) $m->merged_party_id);
                $svc = app(PartyMergeService::class);

                return $data['decision'] === 'APPROVED' ? $svc->approveMerge($m, auth()->user(), $data['note'] ?? null) : $svc->rejectMerge($m, auth()->user(), (string) $data['note']);
            }, __('party_actions.mergeDecision.done')));
    }

    public static function mergeUnmerge(): Action
    {
        $p = 'parties.merge.approve';
        $applied = fn (Model $r) => self::merges($r, 'MERGED');

        return WorkflowAction::make('mergeUnmerge', $p, 'party_actions')->icon('lucide-git-fork')->color('danger')->requiresConfirmation()
            ->visible(fn (Model $record) => $applied($record)->exists())
            ->schema([
                Select::make('merge_id')->label(__('party_actions.fields.merge'))->required()->options(fn (Model $record) => self::mergeOptions($applied($record))),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(3)->maxLength(500),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, function () use ($applied, $record, $data) {
                $m = $applied($record)->findOrFail($data['merge_id']);
                self::resolve((string) $m->merged_party_id);

                return app(PartyMergeService::class)->unmerge($m, auth()->user(), $data['reason']);
            }, __('party_actions.mergeUnmerge.done')));
    }

    /** POST mobile/partner/agent/clients (agent.clients.manage) — AgentLeadService::registerClient; list-page header action. */
    public static function registerClient(): Action
    {
        $p = 'agent.clients.manage';

        return WorkflowAction::make('registerClient', $p, 'party_actions')->icon('lucide-user-round-plus')
            ->schema([
                TextInput::make('full_name')->label(__('party_actions.fields.full_name'))->required()->minLength(3)->maxLength(160),
                TextInput::make('phone_e164')->label(__('party_actions.fields.phone'))->required()->maxLength(32)->placeholder('+2376XXXXXXXX'),
                TextInput::make('city')->label(__('party_actions.fields.city'))->required()->maxLength(80),
                Toggle::make('consent_confirmed')->label(__('party_actions.fields.consent_confirmed'))->accepted()->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                try {
                    return app(AgentLeadService::class)->registerClient($data, auth()->user(), self::tenant());
                } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                    abort(403, $e->getMessage());   // not an (active) agent: shown as a refusal, as the API returns 403
                }
            }, __('party_actions.registerClient.done')));
    }

    /**
     * POST mobile/broker/clients (crm.leads.manage) — AgentClientIntakeService::registerForBroker; /broker customers
     * page header action (owner decision 2026-09-29: the broker portal is writable). The client is attributed to the
     * caller's brokerage (origin-locked), exactly as the API.
     */
    public static function registerBrokerClient(): Action
    {
        $p = 'crm.leads.manage';

        return WorkflowAction::make('brokerRegisterClient', $p, 'broker_portal_sales')->icon('lucide-user-round-plus')
            ->schema([
                TextInput::make('full_name')->label(__('party_actions.fields.full_name'))->required()->minLength(3)->maxLength(160),
                TextInput::make('phone_e164')->label(__('party_actions.fields.phone'))->required()->maxLength(32)->placeholder('+2376XXXXXXXX'),
                TextInput::make('consent_reference')->label(__('broker_portal_sales.fields.consent_reference'))->required()->maxLength(255),
                Toggle::make('consent_confirmed')->label(__('party_actions.fields.consent_confirmed'))->accepted()->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                try {
                    return app(\App\Application\Agents\AgentClientIntakeService::class)->registerForBroker([
                        'type' => 'PERSON', 'display_name' => $data['full_name'], 'phone_e164' => $data['phone_e164'], 'notice_version' => 'broker-2026-01',
                        'evidence_reference' => $data['consent_reference'], 'consent' => true,
                    ], auth()->user(), self::tenant());
                } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                    abort(403, $e->getMessage());   // not a broker user: shown as a refusal, as the API returns 403
                }
            }));
    }

    /** Merges involving the record's party (as survivor or merged), in the given status. */
    private static function merges(Model $record, string $status): \Illuminate\Database\Eloquent\Builder
    {
        $id = self::party($record)->id;

        return PartyMerge::where('status', $status)->where(fn ($q) => $q->where('survivor_party_id', $id)->orWhere('merged_party_id', $id));
    }

    private static function mergeOptions(\Illuminate\Database\Eloquent\Builder $q): array
    {
        return $q->get()->mapWithKeys(fn ($m) => [$m->id => (Party::find($m->merged_party_id)?->display_name ?? '?').' → '.(Party::find($m->survivor_party_id)?->display_name ?? '?')])->all();
    }

    public static function kycOpen(): Action
    {
        $p = 'kyc.manage';

        return WorkflowAction::make('partyKycOpen', $p)->icon('lucide-id-card')->requiresConfirmation()
            ->visible(fn (Model $record) => self::kycDraft($record) === null)
            ->action(fn (Action $action, Model $record) => WorkflowAction::run($action, $p, fn () => app(KycService::class)->draftFor(self::kycParty($record), self::tenant())));
    }

    public static function kycSubmit(): Action
    {
        $p = 'kyc.manage';

        return WorkflowAction::make('partyKycSubmit', $p)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (Model $record) => self::kycDraft($record) !== null)
            ->schema([Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000)])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KycService::class)->submit(self::kycDraft($record) ?? abort(404), $data['notes'] ?? null, auth()->user())));
    }

    public static function requestMerge(): Action
    {
        $p = 'parties.merge.request';

        return WorkflowAction::make('partyRequestMerge', $p)->icon('lucide-merge')->requiresConfirmation()
            ->schema([
                Select::make('merged_party_id')->label(__('workflow_actions.fields.merged_party'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search, Model $record) => self::partySearch($search, self::party($record)->id))->getOptionLabelUsing(fn ($value) => Party::find($value)?->display_name)
                    ->helperText(__('workflow_actions.partyRequestMerge.survivor_hint')),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(500),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartyMergeService::class)->request(
                self::party($record), self::resolve($data['merged_party_id']), auth()->user(), [], $data['reason'] ?? null)));
    }

    public static function recordConsent(): Action
    {
        $p = 'privacy.consent.manage';

        return WorkflowAction::make('partyRecordConsent', $p)->icon('lucide-thumbs-up')
            ->schema([
                Select::make('purpose')->label(__('workflow_actions.fields.purpose'))->required()->options(fn () => WorkflowAction::options(self::consentPurposes())),
                TextInput::make('notice_version')->label(__('workflow_actions.fields.notice_version'))->required()->maxLength(32),
                Select::make('channel')->label(__('workflow_actions.fields.channel'))->required()->options(WorkflowAction::options(['WEB', 'ASSISTED', 'PAPER', 'API'])),
                TextInput::make('evidence_reference')->label(__('workflow_actions.fields.evidence_reference'))->required()->maxLength(255),
                Toggle::make('affirmed')->label(__('workflow_actions.fields.affirmed'))->accepted()->required(),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ConsentService::class)->grant(
                self::party($record), Tenant::findOrFail(self::tenant()), $data['purpose'], $data['notice_version'], $data['channel'],
                ['affirmed' => true, 'reference' => $data['evidence_reference']], auth()->user())));
    }

    public static function assignRole(): Action
    {
        $p = 'parties.roles.manage';

        return WorkflowAction::make('partyAssignRole', $p)->icon('lucide-user-plus')
            ->schema([
                Select::make('role_code')->label(__('workflow_actions.fields.role'))->required()->searchable()
                    ->options(fn () => collect(PartyRoleService::ROLES)->mapWithKeys(fn ($r, $code) => [$code => $r[app()->getLocale() === 'fr' ? 'fr' : 'en']])->all()),
                Select::make('context_type')->label(__('workflow_actions.fields.context_type'))->options(WorkflowAction::options(PartyRoleService::CONTEXT_TYPES)),
                TextInput::make('context_id')->label(__('workflow_actions.fields.context_id'))->uuid()->requiredWith('context_type'),
                DatePicker::make('valid_from')->label(__('workflow_actions.fields.valid_from')),
                DatePicker::make('valid_to')->label(__('workflow_actions.fields.valid_to')),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartyRoleService::class)->assign(
                self::party($record), array_filter($data, fn ($v) => filled($v)), self::tenant(), auth()->id())));
    }

    public static function endRole(): Action
    {
        $p = 'parties.roles.manage';
        $active = fn (Model $r) => PartyRole::where('tenant_id', self::tenant())->where('party_id', self::party($r)->id)->whereNull('valid_to')->whereNull('superseded_at');

        return WorkflowAction::make('partyEndRole', $p)->icon('lucide-user-minus')->requiresConfirmation()
            ->visible(fn (Model $record) => $active($record)->exists())
            ->schema([
                Select::make('role_id')->label(__('workflow_actions.fields.role'))->required()
                    ->options(fn (Model $record) => $active($record)->get()->mapWithKeys(fn ($r) => [$r->id => $r->role_code.($r->context_type ? ' · '.$r->context_type : '')])),
                DatePicker::make('valid_to')->label(__('workflow_actions.fields.valid_to'))->required()->default(now()),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(3)->maxLength(500),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PartyRoleService::class)->end($active($record)->findOrFail($data['role_id']), (string) $data['valid_to'], $data['reason'], auth()->id())));
    }

    public static function linkRelationship(): Action
    {
        $p = 'parties.relationships.manage';

        return WorkflowAction::make('partyLinkRelationship', $p)->icon('lucide-link')
            ->schema([
                Select::make('type')->label(__('workflow_actions.fields.relationship_type'))->required()
                    ->options(collect(PartyRelationshipService::TYPES)->mapWithKeys(fn ($t, $code) => [$code => $t[0]])->all()),
                Select::make('to_party_id')->label(__('workflow_actions.fields.related_party'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search, Model $record) => self::partySearch($search, self::party($record)->id))->getOptionLabelUsing(fn ($value) => Party::find($value)?->display_name),
                DatePicker::make('valid_from')->label(__('workflow_actions.fields.valid_from')),
                DatePicker::make('valid_to')->label(__('workflow_actions.fields.valid_to')),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartyRelationshipService::class)->link(
                self::party($record), self::resolve($data['to_party_id']), array_filter(collect($data)->except('to_party_id')->all(), fn ($v) => filled($v)), self::tenant(), auth()->id())));
    }

    public static function endRelationship(): Action
    {
        $p = 'parties.relationships.manage';
        $active = fn (Model $r) => PartyRelationship::where('from_party_id', self::party($r)->id)->whereNull('valid_to');

        return WorkflowAction::make('partyEndRelationship', $p)->icon('lucide-scissors')->requiresConfirmation()
            ->visible(fn (Model $record) => $active($record)->exists())
            ->schema([
                Select::make('relationship_id')->label(__('workflow_actions.fields.relationship'))->required()
                    ->options(fn (Model $record) => $active($record)->get()->mapWithKeys(fn ($r) => [$r->id => $r->type.' → '.(Party::find($r->to_party_id)?->display_name ?? $r->to_party_id)])),
                DatePicker::make('valid_to')->label(__('workflow_actions.fields.valid_to')),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(3)->maxLength(500),
            ])
            ->action(fn (Action $action, Model $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PartyRelationshipService::class)->endRelationship(
                $active($record)->findOrFail($data['relationship_id']), $data['reason'], filled($data['valid_to'] ?? null) ? (string) $data['valid_to'] : null)));
    }

    /** The record's party, visible under the same rule as the API (tenant customer, or platform/compliance administrator). */
    public static function party(Model $record): Party
    {
        return self::resolve($record instanceof TenantCustomer ? (string) $record->party_id : (string) $record->getKey());
    }

    private static function resolve(string $id): Party
    {
        $p = Party::findOrFail($id);
        $global = auth()->user()?->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
        abort_unless($global || $p->customers()->where('tenant_id', self::tenant())->exists(), 404);

        return $p;
    }

    /** KycController::party — a customer of this tenant, or a party that already has KYC here. */
    private static function kycParty(Model $record): Party
    {
        $id = $record instanceof TenantCustomer ? (string) $record->party_id : (string) $record->getKey();
        $known = DB::table('tenant_customers')->where('tenant_id', self::tenant())->where('party_id', $id)->exists()
            || KycSubmission::where('tenant_id', self::tenant())->where('party_id', $id)->exists();

        return ($known ? Party::find($id) : null) ?? throw new ApiProblemException('PARTY_NOT_FOUND', 404, 'Party not found in this tenant.');
    }

    private static function kycDraft(Model $record): ?KycSubmission
    {
        $id = $record instanceof TenantCustomer ? (string) $record->party_id : (string) $record->getKey();

        return KycSubmission::where('tenant_id', self::tenant())->where('party_id', $id)->whereIn('status', KycService::EDITABLE)->latest('created_at')->first();
    }

    private static function partySearch(string $search, string $except): array
    {
        return Party::whereKeyNot($except)->whereHas('customers', fn ($q) => $q->where('tenant_id', self::tenant()))
            ->where('display_name', 'ilike', '%'.$search.'%')->limit(25)->pluck('display_name', 'id')->all();
    }

    /** Same list the consent route validates against. */
    private static function consentPurposes(): array
    {
        return array_values(array_unique(['INSURANCE_SERVICES', 'MARKETING', 'DATA_SHARING', 'CLAIMS_PROCESSING',
            ...DB::table('processing_purposes')->where('is_active', true)->whereNotNull('consent_purpose')->pluck('consent_purpose')->all()]));
    }

    private static function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }
}
