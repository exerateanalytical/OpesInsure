<?php
namespace App\Filament\Admin\Resources\CoverageDefinitions\Pages;
use App\Filament\Admin\Resources\CoverageDefinitions\CoverageDefinitionResource;use Filament\Resources\Pages\ListRecords;
final class ListCoverageDefinitions extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string $resource=CoverageDefinitionResource::class;}
