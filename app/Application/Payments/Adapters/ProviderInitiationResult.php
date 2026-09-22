<?php
declare(strict_types=1);namespace App\Application\Payments\Adapters;
final readonly class ProviderInitiationResult{public function __construct(public string$providerReference,public string$status,public array$safeResponse=[]){}}
