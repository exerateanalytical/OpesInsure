<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class KycSubmission extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'party_id', 'status', 'notes', 'submitted_at', 'reviewed_at', 'reviewed_by'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'kyc_submission_documents')->withPivot('purpose');
    }
}
