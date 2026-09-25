<?php

namespace App\Console\Commands;

use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Models\Policy;
use Illuminate\Console\Command;

/** REQ-POL-002: seed policy_versions (+ structured rows) for policies issued before chronology existed. Idempotent. */
final class BackfillPolicyChronology extends Command
{
    protected $signature = 'policies:backfill-chronology {--dry-run : Count only} {--chunk=200}';

    protected $description = 'Create a BACKFILL policy version (structured §84 snapshot) for every issued policy without one';

    public function handle(PolicyChronologyWriter $writer): int
    {
        $query = Policy::query()->whereNotNull('issued_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policy_versions')->whereColumn('policy_versions.policy_id', 'policies.id'));

        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} policies need a chronology backfill.");

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        $query->orderBy('id')->chunkById((int) $this->option('chunk'), function ($policies) use ($writer, &$done, &$failed) {
            foreach ($policies as $policy) {
                try {
                    $writer->record($policy, 'BACKFILL', $policy->coverage_starts_at, ['source_type' => 'backfill']);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$policy->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Backfilled {$done} policies; {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
