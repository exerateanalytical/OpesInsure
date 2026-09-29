<?php
namespace App\Filament\Admin\Resources\UnderwritingCases\Pages;use App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource;use App\Filament\Shared\Pages\RecordDetailPage;final class ViewUnderwritingCase extends RecordDetailPage{protected static string $resource=UnderwritingCaseResource::class;
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\UnderwritingCaseActions::group()];
    }
}
