<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\KycSubmissions\Pages;

use App\Filament\Admin\Resources\KycSubmissions\KycSubmissionResource;
use Filament\Resources\Pages\ListRecords;

final class ListKycSubmissions extends ListRecords
{
    protected static string $resource = KycSubmissionResource::class;
}
