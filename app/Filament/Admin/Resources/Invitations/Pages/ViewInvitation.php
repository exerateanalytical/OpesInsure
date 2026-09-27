<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Invitations\Pages;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewInvitation extends RecordDetailPage
{
    protected static string $resource = InvitationResource::class;
}
