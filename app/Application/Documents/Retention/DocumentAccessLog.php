<?php

declare(strict_types=1);

namespace App\Application\Documents\Retention;

use App\Application\Documents\DocumentGovernanceProblem;
use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** REQ-DOC-009: security-level gate + append-only access log (document_access_log) for staff governance reads. */
final class DocumentAccessLog
{
    public function authorize(User $user, Document $document, string $action, string $purpose): void
    {
        if (! DocumentAccessPolicy::staffMay($user, $document)) {
            $this->record($document, $user, 'DENIED', $purpose);
            throw DocumentGovernanceProblem::make('SECURITY_LEVEL_DENIED', 403, "Security level {$document->security_level} requires an additional permission.");
        }
        $this->record($document, $user, $action, $purpose);
    }

    public function record(Document $document, ?User $user, string $action, string $purpose): void
    {
        DB::table('document_access_log')->insert([
            'document_id' => $document->id, 'actor_id' => $user?->id, 'action' => substr($action, 0, 24), 'purpose' => substr($purpose, 0, 64),
            'request_id' => (string) (request()?->header('X-Request-Id') ?: Str::uuid()), 'occurred_at' => now(),
        ]);
    }

    /** @return list<object> */
    public function history(string $documentId): array
    {
        return DB::table('document_access_log')->where('document_id', $documentId)->orderByDesc('id')->limit(500)->get()->all();
    }
}
