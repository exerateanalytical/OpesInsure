<?php
namespace App\Domain\Rating;

final readonly class RatingResult
{
    public function __construct(public int $basePremiumMinor, public int $taxMinor, public int $feeMinor, public int $totalMinor, public array $breakdown) {}
}
