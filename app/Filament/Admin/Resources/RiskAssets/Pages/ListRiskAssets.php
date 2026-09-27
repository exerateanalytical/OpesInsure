<?php
namespace App\Filament\Admin\Resources\RiskAssets\Pages;
use App\Filament\Admin\Resources\RiskAssets\RiskAssetResource;use Filament\Resources\Pages\ListRecords;
final class ListRiskAssets extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string $resource=RiskAssetResource::class;}
