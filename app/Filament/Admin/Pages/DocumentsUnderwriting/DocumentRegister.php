<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentsUnderwriting;

use App\Filament\Shared\Actions\DocumentActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Stored document register (tenant documents: scan and verification state). Opened by documents.read or documents.review;
 * register / review / time-limited access through DocumentActions (same permissions as POST documents*).
 */
final class DocumentRegister extends DocUwPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-files';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'document-register';

    protected static ?array $permissions = ['documents.read', 'documents.review'];

    protected static string $screen = 'documents';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters): array {
                if ($this->tenantId === null) {
                    return [];
                }

                return self::keyed(DB::table('documents')->leftJoin('parties', 'parties.id', '=', 'documents.party_id')->where('documents.tenant_id', $this->tenantId)
                    ->when($filters['scan_status']['value'] ?? null, fn ($q, $v) => $q->where('documents.scan_status', $v))
                    ->when($filters['verification_status']['value'] ?? null, fn ($q, $v) => $q->where('documents.verification_status', $v))
                    ->orderByDesc('documents.created_at')->limit(200)
                    ->get(['documents.id', 'documents.category', 'documents.mime_type', 'documents.size_bytes', 'documents.scan_status', 'documents.verification_status',
                        'documents.created_at', 'parties.display_name as party']));
            })
            ->columns([
                TextColumn::make('category')->label(self::col('category')),
                TextColumn::make('party')->label(self::col('party')),
                TextColumn::make('mime_type')->label(self::col('mime_type')),
                TextColumn::make('size_bytes')->label(self::col('size_bytes'))->numeric(),
                TextColumn::make('scan_status')->label(self::col('scan_status'))->badge(),
                TextColumn::make('verification_status')->label(self::col('verification_status'))->badge(),
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
            ])
            ->filters([
                SelectFilter::make('scan_status')->label(self::col('scan_status'))->options(array_combine($s = ['PENDING', 'PENDING_SCAN', 'SCAN_UNAVAILABLE', 'CLEAN', 'INFECTED', 'FAILED'], $s)),
                SelectFilter::make('verification_status')->label(self::col('verification_status'))->options(array_combine($v = ['UNVERIFIED', 'VERIFIED', 'REJECTED', 'NEEDS_REVIEW'], $v)),
            ])
            ->headerActions([DocumentActions::register()])
            ->recordActions([DocumentActions::review(), DocumentActions::access()])
            ->emptyStateHeading(__('doc_uw_actions.empty'));
    }
}
