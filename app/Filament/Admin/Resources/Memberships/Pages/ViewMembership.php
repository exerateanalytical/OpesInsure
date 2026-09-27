<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Memberships\Pages;

use App\Filament\Admin\Resources\Memberships\MembershipResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewMembership extends RecordDetailPage
{
    protected static string $resource = MembershipResource::class;
}
