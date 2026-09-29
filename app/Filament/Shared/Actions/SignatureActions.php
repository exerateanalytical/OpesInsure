<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Documents\Signatures\SignatureService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;

/**
 * The signed-in user's own pending e-signatures (MySignatureRequests page). Same validation and service as
 * DocumentGovernanceController::sign / ::decline (routes/api.php, v1/signature-requests; no permission — the service only
 * lets the named signer act, in signing order, on an unexpired request whose document hash has not changed):
 *   sigSign     POST signature-requests/{r}/sign      SignatureService::sign   (consent_accepted required)
 *   sigDecline  POST signature-requests/{r}/decline   SignatureService::decline
 */
final class SignatureActions
{
    private const LANG = 'doc_uw_actions';

    public static function sign(): Action
    {
        return WorkflowAction::make('sigSign', null, self::LANG)->icon('lucide-pen-line')
            ->schema(fn (array $record) => [
                Placeholder::make('consent_text')->label(__('doc_uw_actions.fields.consent_text'))->content((string) ($record['consent_text'] ?? '')),
                Checkbox::make('consent_accepted')->label(__('doc_uw_actions.fields.consent_accepted'))->accepted(),
            ])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(SignatureService::class)->sign((string) $record['id'], auth()->user(), [
                    'consent_accepted' => (bool) ($data['consent_accepted'] ?? false), 'ip' => request()->ip(), 'user_agent' => request()->userAgent(),
                ]), __('doc_uw_actions.sigSign.done')));
    }

    public static function decline(): Action
    {
        return WorkflowAction::make('sigDecline', null, self::LANG)->icon('lucide-circle-x')->color('danger')
            ->schema([Textarea::make('reason')->label(__('doc_uw_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, null,
                fn () => app(SignatureService::class)->decline((string) $record['id'], auth()->user(), $data['reason']), __('doc_uw_actions.sigDecline.done')));
    }
}
