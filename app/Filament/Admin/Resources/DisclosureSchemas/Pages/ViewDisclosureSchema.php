<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DisclosureSchemas\Pages;

use App\Filament\Admin\Resources\DisclosureSchemas\DisclosureSchemaResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewDisclosureSchema extends RecordDetailPage
{
    protected static string $resource = DisclosureSchemaResource::class;
}
