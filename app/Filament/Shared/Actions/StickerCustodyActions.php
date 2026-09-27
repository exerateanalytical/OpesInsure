<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Stickers\StickerCustodyService;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\DB;

/**
 * Sticker custody chain (mount on the sticker inventory list). Same services / permissions / extra checks as StickerCustodyController:
 *   stickerHandover        POST sticker-handovers                          stickers.handover   StickerCustodyService::holder|initiateHandover
 *   stickerHandoverDecide  POST sticker-handovers/{h}/accept|reject|cancel stickers.handover   StickerCustodyService::accept|reject
 *   stickerReconcile       POST sticker-reconciliations                    stickers.reconcile  StickerCustodyService::holder|reconcile
 */
final class StickerCustodyActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::handover(), self::decideHandover(), self::reconcile()];
    }

    private static function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }

    private static function f(string $key): string
    {
        return __("workflow_actions.policy_ops_fields.{$key}");
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function holderFields(string $prefix, array $levels): array
    {
        return [
            Select::make("{$prefix}_level")->label(self::f("{$prefix}_level"))->required()->live()->options(WorkflowAction::options($levels)),
            Select::make("{$prefix}_branch_id")->label(self::f('branch'))->visible(fn (callable $get) => $get("{$prefix}_level") === 'BRANCH')->required(fn (callable $get) => $get("{$prefix}_level") === 'BRANCH')
                ->options(fn () => DB::table('tenant_branches')->where(['tenant_id' => self::tenant(), 'status' => 'ACTIVE'])->whereNull('deleted_at')->pluck('name', 'id')->all()),
            Select::make("{$prefix}_user_id")->label(self::f('agent'))->searchable()->visible(fn (callable $get) => $get("{$prefix}_level") === 'AGENT')->required(fn (callable $get) => $get("{$prefix}_level") === 'AGENT')
                ->options(fn () => DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where(['m.tenant_id' => self::tenant(), 'm.status' => 'ACTIVE'])->pluck('u.full_name', 'u.id')->all()),
        ];
    }

    private static function carrierField(): Select
    {
        return Select::make('carrier_id')->label(__('workflow_actions.fields.carrier'))->required()->searchable()
            ->options(fn () => DB::table('carriers as c')->leftJoin('parties as p', 'p.id', '=', 'c.party_id')->where('c.status', 'ACTIVE')->pluck('p.display_name', 'c.id')->all());
    }

    /** @return list<string> serials typed one per line or comma-separated */
    private static function serials(?string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $text) ?: []))));
    }

    private static function holderInput(array $data, string $prefix): array
    {
        return ['level' => $data["{$prefix}_level"], 'branch_id' => $data["{$prefix}_branch_id"] ?? null, 'user_id' => $data["{$prefix}_user_id"] ?? null];
    }

    public static function handover(): Action
    {
        $p = 'stickers.handover';

        return WorkflowAction::make('stickerHandover', $p)->icon('lucide-arrow-right-left')
            ->schema([
                self::carrierField(),
                ...self::holderFields('from', StickerCustodyService::LEVELS),
                ...self::holderFields('to', StickerCustodyService::LEVELS),
                Textarea::make('serial_numbers')->label(self::f('serial_numbers'))->required()->rows(4),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(1000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $user = auth()->user();
                $svc = app(StickerCustodyService::class);
                // The same extra checks the controller applies on top of stickers.handover.
                abort_if(($data['from_level'] === 'CARRIER' || $data['to_level'] === 'CARRIER') && ! $user->hasPermission('stickers.allocate.carrier'), 403,
                    'Allocating or returning carrier sticker stock requires stickers.allocate.carrier.');
                $from = $svc->holder(self::holderInput($data, 'from'), self::tenant());
                $to = $svc->holder(self::holderInput($data, 'to'), self::tenant());
                abort_if($from['level'] === 'AGENT' && $from['user_id'] !== $user->id && ! $user->hasPermission('stickers.allocate'), 403, 'Only the agent holding these stickers can return them.');
                abort_if($from['level'] !== 'AGENT' && ! $user->hasPermission('stickers.allocate'), 403, 'Allocating stickers requires stickers.allocate.');
                $serials = self::serials($data['serial_numbers']);
                abort_if($serials === [] || count($serials) > 5000, 422, 'Enter between 1 and 5000 serial numbers.');

                return $svc->initiateHandover($data['carrier_id'], $from, $to, $serials, $user, filled($data['notes'] ?? null) ? $data['notes'] : null);
            }));
    }

    public static function decideHandover(): Action
    {
        $p = 'stickers.handover';
        $pending = fn () => DB::table('sticker_handovers')->where('status', 'PENDING')
            ->where(fn ($q) => $q->where('from_tenant_id', self::tenant())->orWhere('to_tenant_id', self::tenant()))
            ->orderByDesc('created_at')->get()->mapWithKeys(fn ($h) => [$h->id => "{$h->from_level} → {$h->to_level} · {$h->quantity} · ".substr((string) $h->created_at, 0, 16)])->all();

        return WorkflowAction::make('stickerHandoverDecide', $p)->icon('lucide-package-check')->requiresConfirmation()
            ->visible(fn () => $pending() !== [])
            ->schema([
                Select::make('handover_id')->label(self::f('handover'))->required()->options($pending),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->required()->live()->options([
                    'ACCEPT' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject'), 'CANCEL' => self::f('cancel_handover'),
                ]),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(500)->required(fn (callable $get) => in_array($get('outcome'), ['REJECT', 'CANCEL'], true)),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $svc = app(StickerCustodyService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'ACCEPT'
                    ? $svc->accept($data['handover_id'], auth()->user(), self::tenant())
                    : $svc->reject($data['handover_id'], auth()->user(), self::tenant(), (string) $data['reason'], $data['outcome'] === 'CANCEL'));
            });
    }

    public static function reconcile(): Action
    {
        $p = 'stickers.reconcile';

        return WorkflowAction::make('stickerReconcile', $p)->icon('lucide-clipboard-check')
            ->schema([
                self::carrierField(),
                ...self::holderFields('holder', ['BROKER', 'BRANCH', 'AGENT']),
                Textarea::make('counted_serials')->label(self::f('counted_serials'))->rows(4),
                Textarea::make('damaged_serials')->label(self::f('damaged_serials'))->rows(2),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(1000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $svc = app(StickerCustodyService::class);
                $holder = $svc->holder(self::holderInput($data, 'holder'), self::tenant());

                return $svc->reconcile($data['carrier_id'], $holder, self::serials($data['counted_serials'] ?? null), self::serials($data['damaged_serials'] ?? null),
                    auth()->user(), filled($data['notes'] ?? null) ? $data['notes'] : null);
            }));
    }
}
