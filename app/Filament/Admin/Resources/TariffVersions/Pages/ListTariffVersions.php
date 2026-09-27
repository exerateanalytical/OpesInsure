<?php
namespace App\Filament\Admin\Resources\TariffVersions\Pages;
use App\Filament\Admin\Resources\TariffVersions\TariffVersionResource;use Filament\Resources\Pages\ListRecords;
final class ListTariffVersions extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string $resource=TariffVersionResource::class;}
