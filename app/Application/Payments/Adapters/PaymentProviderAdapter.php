<?php
declare(strict_types=1);namespace App\Application\Payments\Adapters;
use App\Models\PaymentIntentRecord;
interface PaymentProviderAdapter{public function provider():string;public function requestCustomerAuthorization(PaymentIntentRecord$intent,string$requestId):ProviderInitiationResult;}
