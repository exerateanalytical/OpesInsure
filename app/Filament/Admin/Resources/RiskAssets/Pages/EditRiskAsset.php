<?php
namespace App\Filament\Admin\Resources\RiskAssets\Pages;
use App\Application\Risks\RiskAssetService;use App\Filament\Admin\Concerns\NotifiesServiceValidationErrors;use App\Filament\Admin\Resources\RiskAssets\RiskAssetResource;use App\Models\RiskAsset;use Filament\Resources\Pages\EditRecord;
final class EditRiskAsset extends EditRecord{use NotifiesServiceValidationErrors;protected static string $resource=RiskAssetResource::class;protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model$record,array$data):RiskAsset{return app(RiskAssetService::class)->update($record,(int)$record->version,$data,auth()->user());}}
