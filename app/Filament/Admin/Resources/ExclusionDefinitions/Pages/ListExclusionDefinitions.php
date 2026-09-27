<?php
namespace App\Filament\Admin\Resources\ExclusionDefinitions\Pages;
use App\Filament\Admin\Resources\ExclusionDefinitions\ExclusionDefinitionResource;use Filament\Resources\Pages\ListRecords;
final class ListExclusionDefinitions extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string $resource=ExclusionDefinitionResource::class;}
