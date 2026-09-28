<?php
namespace App\Filament\Admin\Resources\Parties\Pages;use App\Filament\Admin\Resources\Parties\PartyResource;use Filament\Resources\Pages\ListRecords;final class ListParties extends ListRecords{protected static string$resource=PartyResource::class;protected function getHeaderActions():array{return[\App\Filament\Shared\Actions\PartyActions::registerClient()];}}
