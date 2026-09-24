<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine\Contracts;

use App\Domain\Shared\StateMachine\TransitionResult;

interface TransitionHistoryRecorder
{
    public function record(TransitionResult $result): void;
}
