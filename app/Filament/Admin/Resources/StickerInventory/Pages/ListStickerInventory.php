<?php
namespace App\Filament\Admin\Resources\StickerInventory\Pages;use App\Filament\Admin\Resources\StickerInventory\StickerInventoryResource;use Filament\Resources\Pages\ListRecords;final class ListStickerInventory extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string $resource=StickerInventoryResource::class;
    protected function getHeaderActions(): array { return \App\Filament\Shared\Actions\StickerCustodyActions::all(); }
}
