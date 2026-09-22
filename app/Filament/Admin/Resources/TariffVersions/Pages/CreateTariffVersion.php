<?php
namespace App\Filament\Admin\Resources\TariffVersions\Pages;
use App\Application\Rating\TariffGovernanceService;use App\Filament\Admin\Concerns\NotifiesServiceValidationErrors;use App\Filament\Admin\Resources\TariffVersions\TariffVersionResource;use App\Models\{InsuranceProduct,TariffVersion};use Filament\Resources\Pages\CreateRecord;
final class CreateTariffVersion extends CreateRecord{use NotifiesServiceValidationErrors;protected static string $resource=TariffVersionResource::class;protected function handleRecordCreation(array$data):TariffVersion{return app(TariffGovernanceService::class)->create(InsuranceProduct::findOrFail($data['insurance_product_id']),$data,auth()->user());}}
