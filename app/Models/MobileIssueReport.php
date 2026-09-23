<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MobileIssueReport extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'route', 'platform', 'app_version', 'note', 'status', 'ip_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
