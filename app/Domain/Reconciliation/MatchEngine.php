<?php
namespace App\Domain\Reconciliation;
final readonly class MatchDecision{public function __construct(public string $status,public ?string $exceptionCode) {}}
final class MatchEngine{public function decide(int $statementAmount,int $systemAmount,string $statementCurrency,string $systemCurrency,bool $referenceMatched):MatchDecision{if(!$referenceMatched)return new MatchDecision('EXCEPTION','REFERENCE_NOT_FOUND');if($statementCurrency!==$systemCurrency)return new MatchDecision('EXCEPTION','CURRENCY_MISMATCH');if($statementAmount!==$systemAmount)return new MatchDecision('EXCEPTION','AMOUNT_MISMATCH');return new MatchDecision('MATCHED',null);}}
