<?php
namespace App\Filament\Admin\Resources\Claims\Pages;use App\Application\Claims\ClaimLifecycleService;use App\Filament\Admin\Concerns\ServiceValidation;use App\Filament\Admin\Resources\Claims\ClaimResource;use App\Models\User;use Filament\Actions\Action;use Filament\Forms\Components\{Select,TextInput,Textarea};use Filament\Resources\Pages\ViewRecord;
final class ViewClaim extends ViewRecord{protected static string$resource=ClaimResource::class;protected function getHeaderActions():array{return[\App\Filament\Shared\Actions\ClaimActions::group()];}}
