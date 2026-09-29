<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\BrokerServicingActions;
use App\Models\Policy;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-071 Document Generation Queue (WF-030): in-force policies of the book still waiting for their documents — no
 * VALID certificate (policy_certificates) — with the last generated document pack (document_pack_manifests). The
 * certificate is issued by the insurer (POST certificates, certificates.issue, on the policy page for holders of that
 * permission); the broker chases it with a servicing request (DOCUMENT_REISSUE ..., BrokerServicingActions::serviceRequest).
 */
final class DocumentGenerationQueuePage extends PolicyScreen
{
    protected static string $key = 'document_queue';

    protected static ?string $slug = 'document-generation-queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-clock';

    protected static ?int $navigationSort = 80;

    protected function query(): Builder
    {
        return $this->policies(['ACTIVE', 'ISSUED', 'EXPIRING', 'AMENDED'])
            ->whereNotIn('policies.id', DB::table('policy_certificates')->where('status', 'VALID')->select('policy_id'));
    }

    protected function columns(): array
    {
        return [
            self::text('policy_number', 'policy')->searchable()->copyable(),
            self::text('party.display_name', 'customer')->searchable(),
            self::status(),
            self::date('issued_at', 'issued_at'),
            TextColumn::make('last_pack')->label(self::col('last_pack'))->state(function (Policy $record): string {
                $m = DB::table('document_pack_manifests')->where('policy_id', $record->id)->orderByDesc('generated_at')->first(['pack_code', 'generated_at']);

                return $m === null ? __('broker_screens_b.document_queue.none') : $m->pack_code.' · '.($m->generated_at ? \Illuminate\Support\Carbon::parse($m->generated_at)->format('d/m/Y H:i') : '—');
            }),
            TextColumn::make('waiting')->label(self::col('waiting_days'))->alignEnd()
                ->state(fn (Policy $record) => $record->issued_at ? (int) $record->issued_at->diffInDays(now()) : '—'),
        ];
    }

    protected function recordActions(): array
    {
        return [BrokerServicingActions::serviceRequest()];
    }
}
