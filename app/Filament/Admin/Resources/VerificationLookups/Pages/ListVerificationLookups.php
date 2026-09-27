<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VerificationLookups\Pages;

use App\Filament\Admin\Resources\VerificationLookups\VerificationLookupResource;
use Filament\Resources\Pages\ListRecords;

final class ListVerificationLookups extends ListRecords
{
    protected static string $resource = VerificationLookupResource::class;
}
