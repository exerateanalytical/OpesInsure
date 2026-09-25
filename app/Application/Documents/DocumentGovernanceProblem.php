<?php

declare(strict_types=1);

namespace App\Application\Documents;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Batch 8-9: a refused document-governance action (intake, retention, legal hold, destruction, signature). */
final class DocumentGovernanceProblem extends HttpException
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
