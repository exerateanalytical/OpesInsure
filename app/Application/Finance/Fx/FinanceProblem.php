<?php

declare(strict_types=1);

namespace App\Application\Finance\Fx;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Batch 9-7: a refused cashier / FX action. */
final class FinanceProblem extends HttpException
{
    public function __construct(public readonly string $problemCode, int $status, string $message)
    {
        parent::__construct($status, $message);
    }

    public static function make(string $code, int $status, string $message): self
    {
        return new self($code, $status, $message);
    }
}
