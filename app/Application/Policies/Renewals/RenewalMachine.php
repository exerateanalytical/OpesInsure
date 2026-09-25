<?php

declare(strict_types=1);

namespace App\Application\Policies\Renewals;

use App\Models\RenewalCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REN-001 renewal case machine (WF-039..043, WF-087).
 *
 *   DUE ─contact→ CONTACTED ─quote (re-rated)→ QUOTED ─successor issued→ RENEWED
 *    │               │                           │  ↑
 *    └──── lapse ────┴→ LAPSED                   ↓  │ issuance recovered
 *                                         ISSUANCE_FAILED ─refund→ DECLINED
 *
 * Reminder windows (days before coverage ends) are reached in order 90 → 60 → 30 → 15 → 7; each is recorded once.
 * Every transition is written to renewal_case_events (append-only).
 */
final class RenewalMachine
{
    public const WINDOWS = [90, 60, 30, 15, 7];

    public const OPEN = ['DUE', 'CONTACTED', 'QUOTED', 'ISSUANCE_FAILED'];

    public const TRANSITIONS = [
        'DUE' => ['CONTACTED', 'QUOTED', 'LAPSED', 'DECLINED'],
        'CONTACTED' => ['QUOTED', 'LAPSED', 'DECLINED'],
        'QUOTED' => ['ISSUANCE_FAILED', 'RENEWED', 'DECLINED'],
        'ISSUANCE_FAILED' => ['QUOTED', 'RENEWED', 'DECLINED'],
        'RENEWED' => [],
        'LAPSED' => [],
        'DECLINED' => [],
    ];

    /** The window a case is in, given the days left before coverage ends; null when further out than 90 days. */
    public static function windowFor(int $daysLeft): ?int
    {
        $window = null;
        foreach (self::WINDOWS as $w) {
            if ($daysLeft <= $w) {
                $window = $w;
            }
        }

        return $window;
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Moves the (locked) case and writes the trail. */
    public function move(RenewalCase $case, string $to, string $action, ?User $actor = null, array $meta = [], array $fields = []): RenewalCase
    {
        $from = $case->status;
        if (! self::canMove($from, $to)) {
            throw ValidationException::withMessages(['status' => "Renewal case cannot move from {$from} to {$to}."]);
        }
        $case->forceFill($fields + ['status' => $to])->save();
        $this->trail($case, $action, $from, $to, $actor, $meta);

        return $case;
    }

    public function trail(RenewalCase $case, string $action, ?string $from, string $to, ?User $actor = null, array $meta = [], ?int $window = null): bool
    {
        return DB::table('renewal_case_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'renewal_case_id' => $case->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'window_days' => $window, 'actor_id' => $actor?->id, 'metadata' => json_encode((object) $meta), 'occurred_at' => now(),
        ]) > 0;
    }
}
