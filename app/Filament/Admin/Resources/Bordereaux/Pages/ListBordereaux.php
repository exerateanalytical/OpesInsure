<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Bordereaux\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Admin\Resources\Bordereaux\BordereauResource;
use App\Filament\Shared\Actions\BordereauActions;
use Filament\Resources\Pages\ListRecords;

final class ListBordereaux extends ListRecords
{
    protected static string $resource = BordereauResource::class;

    /** Finance actions are offered in the admin panel only; the broker / insurer portals reuse this page read-only (owner decision D4). */
    protected function getHeaderActions(): array
    {
        return PortalScope::panel() === null ? [BordereauActions::prepare()] : [];
    }
}
