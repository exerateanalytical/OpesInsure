<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use App\Application\Cases\CaseVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REQ-CAS-001 canonical case ("cases" table; `Case` is a PHP keyword).
 * Confidentiality is enforced at the query layer (INV-6.5) by a global scope.
 */
final class WorkCase extends Model
{
    use HasUuids;

    protected $table = 'cases';

    protected $guarded = [];

    protected $casts = ['opened_at' => 'datetime', 'first_responded_at' => 'datetime', 'due_at' => 'datetime', 'closed_at' => 'datetime', 'legal_hold' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope('confidentiality', fn ($q) => CaseVisibility::apply($q));
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CaseTask::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id')->orderBy('seq');
    }

    public function clocks(): HasMany
    {
        return $this->hasMany(SlaClock::class, 'case_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(CaseDecision::class, 'case_id')->orderBy('decided_at');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(WorkQueue::class, 'queue_id');
    }
}
