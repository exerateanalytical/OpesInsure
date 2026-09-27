<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CertificateTemplates\Pages;

use App\Filament\Admin\Resources\CertificateTemplates\CertificateTemplateResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCertificateTemplate extends RecordDetailPage
{
    protected static string $resource = CertificateTemplateResource::class;
}
