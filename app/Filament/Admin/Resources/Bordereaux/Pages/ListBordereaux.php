<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Bordereaux\Pages;

use App\Filament\Admin\Resources\Bordereaux\BordereauResource;
use App\Filament\Shared\Actions\BordereauActions;
use Filament\Resources\Pages\ListRecords;

final class ListBordereaux extends ListRecords
{
    protected static string $resource = BordereauResource::class;

    /** Every panel (D4 lifted 2026-09-29): gated by the API permission (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [BordereauActions::prepare()];
    }
}
