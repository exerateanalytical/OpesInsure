<?php
namespace App\Filament\Admin\Resources\RiskAssets\Pages;
use App\Application\Risks\RiskAssetService;use App\Domain\Tenancy\TenantContext;use App\Filament\Admin\Concerns\NotifiesServiceValidationErrors;use App\Filament\Admin\Resources\RiskAssets\RiskAssetResource;use App\Models\{Party,RiskAsset,Tenant};use Filament\Resources\Pages\CreateRecord;
final class CreateRiskAsset extends CreateRecord{use NotifiesServiceValidationErrors;protected static string $resource=RiskAssetResource::class;protected function handleRecordCreation(array$data):RiskAsset{$tenant=Tenant::findOrFail(app(TenantContext::class)->id());return app(RiskAssetService::class)->create($tenant,Party::findOrFail($data['party_id']),$data,auth()->user());}}
