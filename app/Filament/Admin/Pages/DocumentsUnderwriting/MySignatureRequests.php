<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentsUnderwriting;

use App\Filament\Shared\Actions\SignatureActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Documents awaiting the signed-in user's own e-signature (pending signer rows naming the user or the user's party).
 * Sign / decline through SignatureActions -> SignatureService, which re-checks signer, order, expiry and document hash.
 */
final class MySignatureRequests extends DocUwPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-pen-line';

    protected static ?int $navigationSort = 63;

    protected static ?string $slug = 'my-signatures';

    protected static ?array $permissions = null;

    protected static string $screen = 'signatures';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $user = auth()->user();
                if ($user === null) {
                    return [];
                }

                return self::keyed(DB::table('signature_requests as r')->join('signature_request_signers as s', 's.signature_request_id', '=', 'r.id')
                    ->join('documents as d', 'd.id', '=', 'r.document_id')
                    ->where('r.status', 'PENDING')->where('s.status', 'PENDING')
                    ->where(fn ($q) => $q->where('s.signer_user_id', $user->id)->when($user->party_id, fn ($w) => $w->orWhere('s.signer_party_id', $user->party_id)))
                    ->orderBy('r.created_at')->limit(200)
                    ->get(['r.id', 'r.consent_text', 'r.expires_at', 'r.created_at', 'r.provider', 'd.category', 's.signer_role', 's.signing_order'])
                    ->unique('id'));
            })
            ->columns([
                TextColumn::make('category')->label(self::col('category')),
                TextColumn::make('signer_role')->label(self::col('signer_role')),
                TextColumn::make('signing_order')->label(self::col('signing_order')),
                TextColumn::make('provider')->label(self::col('provider')),
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
                TextColumn::make('expires_at')->label(self::col('expires_at'))->dateTime(),
            ])
            ->recordActions([SignatureActions::sign(), SignatureActions::decline()])
            ->emptyStateHeading(__('doc_uw_actions.empty'));
    }
}
