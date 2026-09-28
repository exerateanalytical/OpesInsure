<?php
namespace App\Filament\Admin\Resources\Renewals\Pages;use App\Filament\Admin\Resources\Renewals\RenewalResource;use Filament\Resources\Pages\ListRecords;final class ListRenewals extends ListRecords{protected static string $resource=RenewalResource::class;protected function getHeaderActions():array{return[\App\Filament\Shared\Actions\RenewalActions::sweep()];}}
