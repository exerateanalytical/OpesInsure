<?php

declare(strict_types=1);

namespace App\Application\Rules\Console;

use App\Application\Rules\QuestionSetCatalogue;
use Illuminate\Console\Command;

/** REQ-DUP-020 — re-sync the PHP wizard seed schemas into question_sets (new version only when content changed). */
final class SyncQuestionSetsCommand extends Command
{
    protected $signature = 'rules:sync-question-sets';

    protected $description = 'Sync RiskSchemaCatalogue seed schemas into versioned question_sets / product_questions';

    public function handle(QuestionSetCatalogue $catalogue): int
    {
        $this->info('Question sets created: '.$catalogue->syncSeedCatalogue());

        return self::SUCCESS;
    }
}
