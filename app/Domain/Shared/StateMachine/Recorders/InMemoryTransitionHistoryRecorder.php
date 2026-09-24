<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Recorders;

use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\TransitionResult;

final class InMemoryTransitionHistoryRecorder implements TransitionHistoryRecorder
{
    /** @var list<TransitionResult> */
    public array $records = [];

    public function record(TransitionResult $result): void
    {
        $this->records[] = $result;
    }
}
