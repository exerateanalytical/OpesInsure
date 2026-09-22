<?php
declare(strict_types=1);namespace App\Application\Payments\Adapters;
use App\Models\PaymentIntentRecord;
final class FakePaymentAdapter implements PaymentProviderAdapter{public function provider():string{return'fake';}public function requestCustomerAuthorization(PaymentIntentRecord$i,string$r):ProviderInitiationResult{return new ProviderInitiationResult('FAKE-'.$i->id,'PENDING_CUSTOMER',['request_id'=>$r,'sandbox'=>true]);}}
