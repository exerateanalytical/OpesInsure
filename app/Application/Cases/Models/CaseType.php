<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REQ-CAS-001 versioned case type (ICE E6 INV-6.2). A case pins the version it was opened with. */
final class CaseType extends Model
{
    use HasUuids;

    protected $table = 'case_types';

    protected $guarded = [];

    protected $casts = ['states' => 'array', 'transitions' => 'array', 'sla_policies' => 'array', 'auto_tasks' => 'array', 'regulated' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date', 'approved_at' => 'datetime'];

    /** @return array<string, array{terminal?: bool, pauses_sla?: bool, initial?: bool}> */
    public function stateMap(): array
    {
        $out = [];
        foreach ($this->states as $s) {
            $out[$s['code']] = $s;
        }

        return $out;
    }

    public function isTerminal(string $state): bool
    {
        return (bool) ($this->stateMap()[$state]['terminal'] ?? false);
    }

    public function pausesSla(string $state): bool
    {
        return (bool) ($this->stateMap()[$state]['pauses_sla'] ?? false);
    }

    public function initialState(): string
    {
        foreach ($this->states as $s) {
            if (! empty($s['initial'])) {
                return $s['code'];
            }
        }

        return $this->states[0]['code'];
    }
}
