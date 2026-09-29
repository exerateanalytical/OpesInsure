<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Documents\Adapters\SignedUrlAdapter;
use App\Application\Documents\DocumentRegistrationService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Document register actions (DocumentRegister page). Same permission and validation as
 * App\Interfaces\Http\Controllers\Api\V1\Documents\DocumentController (routes/api.php):
 *   docRegister  POST documents                       (authenticated)    DocumentRegistrationService::register
 *   docReview    POST documents/{d}/review            documents.review   DocumentRegistrationService::review (keeps stored OCR)
 *   docAccess    POST documents/{d}/access            (authenticated)    SignedUrlAdapter::sign + document_access_log READ
 */
final class DocumentActions
{
    private const LANG = 'doc_uw_actions';

    /** Same TTL as DocumentController::ACCESS_TTL_SECONDS (private there). */
    private const ACCESS_TTL_SECONDS = 300;

    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public static function register(): Action
    {
        return WorkflowAction::make('docRegister', null, self::LANG)->icon('lucide-file-plus')
            ->schema([
                TextInput::make('category')->label(__('doc_uw_actions.fields.category'))->required()->maxLength(48),
                TextInput::make('storage_key')->label(__('doc_uw_actions.fields.storage_key'))->required()->maxLength(500),
                Select::make('mime_type')->label(__('doc_uw_actions.fields.mime_type'))->required()->options(array_combine(self::MIME_TYPES, self::MIME_TYPES)),
                TextInput::make('size_bytes')->label(__('doc_uw_actions.fields.size_bytes'))->required()->integer()->minValue(1)->maxValue(20971520),
                TextInput::make('sha256')->label(__('doc_uw_actions.fields.sha256'))->required()->length(64),
                Select::make('party_id')->label(__('doc_uw_actions.fields.party'))->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('parties')->where('display_name', 'ilike', "%{$search}%")->limit(50)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('parties')->where('id', $value)->value('display_name')),
            ])
            ->action(function (Action $action, array $data) {
                return WorkflowAction::run($action, null, function () use ($data) {
                    $d = ['party_id' => $data['party_id'] ?? null, 'category' => $data['category'], 'storage_key' => $data['storage_key'], 'mime_type' => $data['mime_type'],
                        'size_bytes' => (int) $data['size_bytes'], 'sha256' => strtolower($data['sha256'])];
                    if ($d['party_id'] !== null && ! DB::table('parties')->where('id', $d['party_id'])->exists()) {
                        throw new ApiProblemException('VALIDATION_FAILED', 422, 'The selected party is invalid.');
                    }

                    return app(DocumentRegistrationService::class)->register(app(TenantContext::class)->id(), $d, auth()->user());
                }, __('doc_uw_actions.docRegister.done'));
            });
    }

    public static function review(): Action
    {
        $p = 'documents.review';

        return WorkflowAction::make('docReview', $p, self::LANG)->icon('lucide-shield-check')
            ->schema([
                Select::make('scan_status')->label(__('doc_uw_actions.fields.scan_status'))->required()
                    ->options(self::codes(['CLEAN', 'INFECTED', 'FAILED'], 'scan')),
                Select::make('verification_status')->label(__('doc_uw_actions.fields.verification_status'))->required()
                    ->options(self::codes(['VERIFIED', 'REJECTED', 'NEEDS_REVIEW'], 'verification')),
                Textarea::make('notes')->label(__('doc_uw_actions.fields.notes'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                // The desktop form never sends OCR data, so stored OCR is kept (null).
                return app(DocumentRegistrationService::class)->review(app(TenantContext::class)->id(), (string) $record['id'], $data['scan_status'], $data['verification_status'], null);
            }, __('doc_uw_actions.docReview.done')));
    }

    public static function access(): Action
    {
        return WorkflowAction::make('docAccess', null, self::LANG)->icon('lucide-external-link')
            ->visible(fn (array $record) => $record['scan_status'] === 'CLEAN')
            ->schema([TextInput::make('purpose')->label(__('doc_uw_actions.fields.purpose'))->required()->maxLength(64)])
            ->action(function (Action $action, array $record, array $data) {
                $signed = WorkflowAction::run($action, null, function () use ($record, $data) {
                    $doc = Document::where('tenant_id', app(TenantContext::class)->id())->where('id', (string) $record['id'])->where('scan_status', 'CLEAN')->first();
                    abort_unless($doc, 404);
                    $signed = app(SignedUrlAdapter::class)->sign($doc, self::ACCESS_TTL_SECONDS);
                    DB::table('document_access_log')->insert(['document_id' => $doc->id, 'actor_id' => auth()->id(), 'action' => 'READ', 'purpose' => $data['purpose'],
                        'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);

                    return $signed;
                }, __('doc_uw_actions.docAccess.done'));
                if ($signed) {
                    Notification::make()->info()->title(__('doc_uw_actions.docAccess.link', ['minutes' => intdiv(self::ACCESS_TTL_SECONDS, 60)]))
                        ->actions([Action::make('open')->label(__('doc_uw_actions.docAccess.open'))->url($signed->url, shouldOpenInNewTab: true)])
                        ->persistent()->send();
                }
            });
    }

    /** @param list<string> $values */
    private static function codes(array $values, string $group): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => WorkflowAction::optional("doc_uw_actions.codes.{$group}.{$v}") ?? $v])->all();
    }
}
