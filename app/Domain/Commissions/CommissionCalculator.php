<?php
namespace App\Domain\Commissions;

use DomainException;

final readonly class CommissionCalculation { public function __construct(public int $grossMinor,public int $holdbackMinor,public int $netAccruedMinor){} }
final class CommissionCalculator
{
    public function calculate(int $premiumMinor,int $basisPoints,int $holdbackBasisPoints):CommissionCalculation{if($premiumMinor<0||$basisPoints<0||$basisPoints>10000||$holdbackBasisPoints<0||$holdbackBasisPoints>10000)throw new DomainException('Invalid commission inputs.');$gross=(int)round($premiumMinor*$basisPoints/10000);$holdback=(int)round($gross*$holdbackBasisPoints/10000);return new CommissionCalculation($gross,$holdback,$gross-$holdback);}
}
