<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages;

use App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPhysicalSecurityAssets extends ListRecords
{
    use \App\Filament\Shared\Concerns\OpensViewPage;

    protected static string $resource = PhysicalSecurityAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
