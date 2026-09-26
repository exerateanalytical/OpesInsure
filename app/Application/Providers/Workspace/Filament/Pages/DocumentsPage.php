<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "documents" (Gap-Free spec ui_screen_register). */
final class DocumentsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'documents';

    protected static string $permission = 'provider.documents.view';

    protected static string $screen = 'documents';

    protected function rows(): array
    {
        if (! app(\App\Application\Providers\Workspace\ProviderAccess::class)->mayReadClinical($this->user(), $this->scope())) {
            $this->state = 'PERMISSION_DENIED';

            return [];
        }
        $m = $this->ws()->preauthQuery($this->tenantId(), $this->user(), $this->scope())->whereNotNull('gop_manifest_id')->pluck('gop_manifest_id');

        return \Illuminate\Support\Facades\DB::table('documents')->whereIn('pack_manifest_id', $m)->limit(500)->get(['document_type_code', 'document_number', 'status', 'valid_from', 'valid_until'])->map(fn ($r) => (array) $r)->all();
    }
}
