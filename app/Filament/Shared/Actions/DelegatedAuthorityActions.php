<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\CarrierOperations\DelegatedAuthorityService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Carrier delegated-authority agreements (DelegatedAuthorities page). Same permission and validation as
 * CarrierOperationsController (routes/api.php):
 *   daCreate   POST carrier/delegated-authorities               carrier.authority.manage   DelegatedAuthorityService::create (DRAFT, records maker)
 *   daApprove  POST carrier/delegated-authorities/{a}/approve   carrier.authority.approve  DelegatedAuthorityService::approve (four-eyes)
 *   daCheck    POST carrier/delegated-authorities/{a}/check     (authenticated)            DelegatedAuthorityService::check (read-only)
 * Amounts are XAF minor units, exactly as the API takes them.
 */
final class DelegatedAuthorityActions
{
    private const LANG = 'doc_uw_actions';

    public const LINES = ['AUTOMOBILE', 'HEALTH', 'TRAVEL', 'PROPERTY'];

    public static function create(): Action
    {
        $p = 'carrier.authority.manage';

        return WorkflowAction::make('daCreate', $p, self::LANG)->icon('lucide-file-signature')
            ->schema([
                Select::make('carrier_id')->label(__('doc_uw_actions.fields.carrier'))->required()->searchable()
                    ->options(fn () => DB::table('carriers')->join('parties', 'parties.id', '=', 'carriers.party_id')->orderBy('parties.display_name')
                        ->get(['carriers.id', 'carriers.cima_code', 'parties.display_name'])->mapWithKeys(fn ($c) => [$c->id => "{$c->display_name} ({$c->cima_code})"])->all()),
                Select::make('partner_id')->label(__('doc_uw_actions.fields.partner'))->required()->searchable()
                    ->options(fn () => DB::table('partners')->join('parties', 'parties.id', '=', 'partners.party_id')->where('partners.tenant_id', app(TenantContext::class)->id())
                        ->orderBy('parties.display_name')->pluck('parties.display_name', 'partners.id')->all()),
                TextInput::make('agreement_number')->label(__('doc_uw_actions.fields.agreement_number'))->required()->maxLength(80),
                DatePicker::make('effective_from')->label(__('doc_uw_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('doc_uw_actions.fields.effective_until'))->required()->after('effective_from'),
                Select::make('permitted_lines')->label(__('doc_uw_actions.fields.permitted_lines'))->required()->multiple()->options(array_combine(self::LINES, self::LINES)),
                TextInput::make('max_policy_premium_minor')->label(__('doc_uw_actions.fields.max_policy_premium_minor'))->required()->integer()->minValue(0),
                TextInput::make('max_claim_authority_minor')->label(__('doc_uw_actions.fields.max_claim_authority_minor'))->required()->integer()->minValue(0),
                TagsInput::make('territories')->label(__('doc_uw_actions.fields.territories'))->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                if (DB::table('delegated_authority_agreements')->where('agreement_number', $data['agreement_number'])->exists()) {
                    throw new ApiProblemException('VALIDATION_FAILED', 422, __('doc_uw_actions.daCreate.duplicate'));
                }
                if (! DB::table('partners')->where('id', $data['partner_id'])->where('tenant_id', app(TenantContext::class)->id())->exists()
                    || ! DB::table('carriers')->where('id', $data['carrier_id'])->exists()) {
                    throw new ApiProblemException('VALIDATION_FAILED', 422, 'The selected carrier or partner is invalid.');
                }
                $lines = array_values($data['permitted_lines']);
                $territories = array_values($data['territories'] ?? []);
                if ($lines === [] || array_diff($lines, self::LINES) !== [] || $territories === []) {
                    throw new ApiProblemException('VALIDATION_FAILED', 422, 'Permitted lines and territories are required.');
                }

                return app(DelegatedAuthorityService::class)->create([
                    'carrier_id' => $data['carrier_id'], 'partner_id' => $data['partner_id'], 'agreement_number' => $data['agreement_number'],
                    'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'], 'permitted_lines' => $lines, 'territories' => $territories,
                    'max_policy_premium_minor' => (int) $data['max_policy_premium_minor'], 'max_claim_authority_minor' => (int) $data['max_claim_authority_minor'],
                ], auth()->user());
            }, __('doc_uw_actions.daCreate.done')));
    }

    public static function approve(): Action
    {
        $p = 'carrier.authority.approve';

        return WorkflowAction::make('daApprove', $p, self::LANG)->icon('lucide-badge-check')
            ->visible(fn (array $record) => $record['status'] === 'DRAFT')
            ->schema([Textarea::make('reason')->label(__('doc_uw_actions.fields.reason'))->required()->minLength(20)->maxLength(2000)])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DelegatedAuthorityService::class)->approve((string) $record['id'], auth()->user(), $data['reason']), __('doc_uw_actions.daApprove.done')));
    }

    public static function check(): Action
    {
        return WorkflowAction::make('daCheck', null, self::LANG)->icon('lucide-search-check')
            ->schema([
                Select::make('line_code')->label(__('doc_uw_actions.fields.line_code'))->required()->options(array_combine(self::LINES, self::LINES)),
                TextInput::make('premium_minor')->label(__('doc_uw_actions.fields.premium_minor'))->required()->integer()->minValue(0),
                TextInput::make('territory')->label(__('doc_uw_actions.fields.territory'))->required(),
                DateTimePicker::make('effective_at')->label(__('doc_uw_actions.fields.effective_at'))->required()->default(now()),
            ])
            ->action(function (Action $action, array $record, array $data) {
                $decision = WorkflowAction::run($action, null, fn () => app(DelegatedAuthorityService::class)->check((string) $record['id'], $data['line_code'],
                    (int) $data['premium_minor'], $data['territory'], new \DateTimeImmutable((string) $data['effective_at'])), __('doc_uw_actions.daCheck.done'));
                if ($decision) {
                    $n = Notification::make()->title(__('doc_uw_actions.daCheck.'.($decision->allowed ? 'within' : 'outside'), ['reason' => $decision->reason]));
                    ($decision->allowed ? $n->success() : $n->warning())->send();
                }
            });
    }
}
