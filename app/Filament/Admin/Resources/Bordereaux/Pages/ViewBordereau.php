<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Bordereaux\Pages;

use App\Filament\Admin\Resources\Bordereaux\BordereauResource;
use App\Filament\Shared\Actions\BordereauActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewBordereau extends RecordDetailPage
{
    protected static string $resource = BordereauResource::class;

    /** Every panel (D4 lifted 2026-09-29): each action is gated by its API permission + own-organisation record (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return BordereauActions::recordActions();
    }
}
