<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Rating\ChargeTableService;
use App\Application\Rating\RatingService;
use App\Domain\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rating v2 actions (UI batch 26). Same permission + service + validation as RatingController (routes/rating.php):
 *   chargeTableCreate          POST rating/charge-tables/{kind}                         rating.charges.manage   ChargeTableService::create
 *   chargeTableApprove         POST rating/charge-tables/{kind}/{id}/approve            rating.charges.approve  ChargeTableService::approve (maker ≠ checker)
 *   chargeVerificationRequest  POST rating/charge-tables/{kind}/{id}/verification       rating.charges.manage   ChargeTableService::requestVerification
 *   chargeVerificationDecide   POST rating/charge-tables/{kind}/{id}/verification/decide rating.charges.verify  ChargeTableService::decideVerification (≠ requester)
 *   ratingRunReproduce         POST rating/runs/{run}/reproduce                          rating.runs.view        RatingService::reproduce
 * A charge table is always created DEMO / UNVERIFIED (owner decision 10): OWNER_CONFIRMED only through the verification step.
 */
final class RatingActions
{
    private const L = 'operations_actions';

    public static function chargeTableCreate(): Action
    {
        $p = 'rating.charges.manage';

        return WorkflowAction::make('chargeTableCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                Select::make('kind')->label(__(self::L.'.fields.kind'))->required()->live()
                    ->options(['tax' => __(self::L.'.codes.kind.tax'), 'fee' => __(self::L.'.codes.kind.fee')]),
                TextInput::make('line_code')->label(__(self::L.'.fields.line_code'))->maxLength(32)
                    ->required(fn (Get $get) => $get('kind') === 'tax')->visible(fn (Get $get) => $get('kind') === 'tax'),
                TextInput::make('jurisdiction')->label(__(self::L.'.fields.jurisdiction'))->length(2)->default('CM')->visible(fn (Get $get) => $get('kind') === 'tax'),
                TextInput::make('code')->label(__(self::L.'.fields.code'))->maxLength(64)
                    ->required(fn (Get $get) => $get('kind') === 'fee')->visible(fn (Get $get) => $get('kind') === 'fee'),
                Toggle::make('tenant_only')->label(__(self::L.'.fields.tenant_only'))->visible(fn (Get $get) => $get('kind') === 'fee'),
                DatePicker::make('effective_from')->label(__(self::L.'.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__(self::L.'.fields.effective_until'))->afterOrEqual('effective_from'),
                Repeater::make('charges')->label(__(self::L.'.fields.charges'))->required()->minItems(1)->columns(4)->schema([
                    Select::make('code')->label(__(self::L.'.fields.charge_code'))->required()
                        ->options(fn () => DB::table('rating_charge_codes')->orderBy('code')->pluck('code', 'code')->all()),
                    Select::make('basis')->label(__(self::L.'.fields.basis'))->required()
                        ->options(['PREMIUM' => 'PREMIUM', 'PREMIUM_AND_FEES' => 'PREMIUM_AND_FEES', 'FIXED' => 'FIXED']),
                    TextInput::make('basis_points')->label(__(self::L.'.fields.basis_points'))->integer()->minValue(0)->maxValue(10000),
                    TextInput::make('fixed_minor')->label(__(self::L.'.fields.fixed_minor'))->integer()->minValue(0),
                ]),
                TextInput::make('source_reference')->label(__(self::L.'.fields.source_reference'))->maxLength(255),
                Textarea::make('legal_basis')->label(__(self::L.'.fields.legal_basis'))->maxLength(2000),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $kind = (string) $data['kind'];
                $charges = array_values(array_map(fn (array $c) => array_filter([
                    'code' => $c['code'], 'basis' => $c['basis'],
                    'basis_points' => filled($c['basis_points'] ?? null) ? (int) $c['basis_points'] : null,
                    'fixed_minor' => filled($c['fixed_minor'] ?? null) ? (int) $c['fixed_minor'] : null,
                ], fn ($v) => $v !== null), $data['charges'] ?? []));
                $d = array_filter([
                    'line_code' => $kind === 'tax' ? ($data['line_code'] ?? null) : null,
                    'jurisdiction' => $kind === 'tax' && filled($data['jurisdiction'] ?? null) ? strtoupper($data['jurisdiction']) : null,
                    'code' => $kind === 'fee' ? ($data['code'] ?? null) : null,
                    'tenant_id' => $kind === 'fee' && ! empty($data['tenant_only']) ? app(TenantContext::class)->id() : null,
                    'effective_from' => Carbon::parse($data['effective_from'])->toDateString(),
                    'effective_until' => filled($data['effective_until'] ?? null) ? Carbon::parse($data['effective_until'])->toDateString() : null,
                    'rules' => ['charges' => $charges],
                    'source_reference' => filled($data['source_reference'] ?? null) ? $data['source_reference'] : null,
                    'legal_basis' => filled($data['legal_basis'] ?? null) ? $data['legal_basis'] : null,
                ], fn ($v) => $v !== null);

                return WorkflowAction::run($action, $p, fn () => app(ChargeTableService::class)->create($kind, $d, auth()->user()), __(self::L.'.chargeTableCreate.done'));
            });
    }

    public static function chargeTableApprove(): Action
    {
        $p = 'rating.charges.approve';

        return WorkflowAction::make('chargeTableApprove', $p, self::L)->icon('lucide-badge-check')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(ChargeTableService::class)->approve((string) $record['kind'], WorkflowAction::id($record), auth()->user()), __(self::L.'.chargeTableApprove.done')));
    }

    public static function chargeVerificationRequest(): Action
    {
        $p = 'rating.charges.manage';

        return WorkflowAction::make('chargeVerificationRequest', $p, self::L)->icon('lucide-file-search')
            ->visible(fn (mixed $record) => in_array($record['verification_status'] ?? null, ['DEMO', 'UNVERIFIED'], true) && ($record['status'] ?? null) !== 'REJECTED')
            ->schema([
                Textarea::make('legal_basis')->label(__(self::L.'.fields.legal_basis'))->required()->minLength(5)->maxLength(2000),
                TextInput::make('source_reference')->label(__(self::L.'.fields.source_reference'))->required()->maxLength(255),
                TextInput::make('source_document')->label(__(self::L.'.fields.source_document'))->maxLength(500),
                Textarea::make('notes')->label(__(self::L.'.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ChargeTableService::class)->requestVerification((string) $record['kind'], WorkflowAction::id($record),
                    array_filter($data, fn ($v) => filled($v)), auth()->user()), __(self::L.'.chargeVerificationRequest.done')));
    }

    public static function chargeVerificationDecide(): Action
    {
        $p = 'rating.charges.verify';

        return WorkflowAction::make('chargeVerificationDecide', $p, self::L)->icon('lucide-scale')
            ->visible(fn (mixed $record) => ($record['verification_status'] ?? null) === 'PENDING_VERIFICATION')
            ->schema([
                Select::make('decision')->label(__(self::L.'.fields.decision'))->required()
                    ->options(['CONFIRM' => __(self::L.'.codes.verification.CONFIRM'), 'REJECT' => __(self::L.'.codes.verification.REJECT')]),
                Textarea::make('notes')->label(__(self::L.'.fields.notes'))->required()->minLength(10)->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ChargeTableService::class)->decideVerification((string) $record['kind'], WorkflowAction::id($record),
                    $data['decision'] === 'CONFIRM', $data['notes'], auth()->user()), __(self::L.'.chargeVerificationDecide.done')));
    }

    public static function ratingRunReproduce(): Action
    {
        $p = 'rating.runs.view';

        return WorkflowAction::make('ratingRunReproduce', $p, self::L)->icon('lucide-repeat')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'SUCCEEDED')
            ->action(function (Action $action, mixed $record) use ($p) {
                $result = WorkflowAction::run($action, $p, function () use ($record) {
                    $id = WorkflowAction::id($record);
                    // Same tenant guard as RatingController::runRow.
                    abort_unless(DB::table('rating_runs')->where('id', $id)->where('tenant_id', app(TenantContext::class)->id())->exists(), 404);
                    try {
                        return app(RatingService::class)->reproduce($id);
                    } catch (DomainException $e) {
                        throw ValidationException::withMessages(['rating_run' => $e->getMessage()]);
                    }
                }, __(self::L.'.ratingRunReproduce.done'));
                if (is_array($result)) {
                    Notification::make()->{$result['identical'] ? 'info' : 'warning'}()
                        ->title(__(self::L.'.ratingRunReproduce.'.($result['identical'] ? 'identical' : 'different')))
                        ->body($result['stored_output_hash'].' / '.$result['reproduced_output_hash'])->send();
                }

                return $result;
            });
    }
}
