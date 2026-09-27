<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Models\DocumentTemplate;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;

/**
 * Document template lifecycle DRAFT → REVIEW → APPROVED → PUBLISHED → RETIRED, plus the one-step approve-publish
 * of a system-seeded template. Same as POST document-templates/{t}/{submit|approve|publish|approve-publish|retire}
 * (permission documents.templates.manage) → DocumentTemplateService; maker-checker, content hash and audit stay there.
 */
final class DocumentTemplateActions
{
    public const PERMISSION = 'documents.templates.manage';

    /** @return list<Action> */
    public static function all(): array
    {
        return [self::submit(), self::approve(), self::publish(), self::approvePublish(), self::retire()];
    }

    public static function submit(): Action
    {
        return self::step('templateSubmit', 'DRAFT', 'lucide-send', fn (DocumentTemplate $t) => app(DocumentTemplateService::class)->submit($t, auth()->user()));
    }

    public static function approve(): Action
    {
        return self::step('templateApprove', 'REVIEW', 'lucide-check', fn (DocumentTemplate $t) => app(DocumentTemplateService::class)->approve($t, auth()->user()))
            ->hidden(fn (DocumentTemplate $record) => self::systemSeeded($record));
    }

    public static function publish(): Action
    {
        return WorkflowAction::make('templatePublish', self::PERMISSION)->icon('lucide-globe')->color('success')->requiresConfirmation()
            ->visible(fn (DocumentTemplate $record) => $record->status === 'APPROVED')
            ->schema([DatePicker::make('effective_from')->label(__('workflow_actions.fields.effective_from'))])
            ->action(fn (Action $action, DocumentTemplate $record, array $data) => WorkflowAction::run($action, self::PERMISSION,
                fn () => app(DocumentTemplateService::class)->publish($record, auth()->user(), $data['effective_from'] ?? null)));
    }

    /** System-seeded templates (ProviderDocumentTemplateSeeder): the administrator is the checker, approve + publish at once. */
    public static function approvePublish(): Action
    {
        return self::step('templateApprovePublish', 'REVIEW', 'lucide-zap', fn (DocumentTemplate $t) => app(DocumentTemplateService::class)->approveAndPublishSystem($t, auth()->user()))
            ->color('success')->visible(fn (DocumentTemplate $record) => $record->status === 'REVIEW' && self::systemSeeded($record));
    }

    public static function retire(): Action
    {
        return WorkflowAction::make('templateRetire', self::PERMISSION)->icon('lucide-archive-x')->color('danger')->requiresConfirmation()
            ->visible(fn (DocumentTemplate $record) => $record->status !== 'RETIRED')
            ->schema([TextInput::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(5)->maxLength(500)])
            ->action(fn (Action $action, DocumentTemplate $record, array $data) => WorkflowAction::run($action, self::PERMISSION,
                fn () => app(DocumentTemplateService::class)->retire($record, $data['reason'], auth()->user())));
    }

    private static function step(string $name, string $from, string $icon, \Closure $call): Action
    {
        return WorkflowAction::make($name, self::PERMISSION)->icon($icon)->requiresConfirmation()
            ->visible(fn (DocumentTemplate $record) => $record->status === $from)
            ->action(fn (Action $action, DocumentTemplate $record) => WorkflowAction::run($action, self::PERMISSION, fn () => $call($record)));
    }

    private static function systemSeeded(DocumentTemplate $t): bool
    {
        return $t->created_by === \Database\Seeders\ProviderDocumentTemplateSeeder::SYSTEM_USER_ID && ! empty($t->content['system_seeded']);
    }
}
