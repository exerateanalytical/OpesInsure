<?php
declare(strict_types=1);namespace App\Application\Payments\Adapters;use InvalidArgumentException;
final class PaymentAdapterRegistry{public function for(string$p):PaymentProviderAdapter{return match($p){'fake'=>new FakePaymentAdapter,'mtn_momo'=>new MtnMomoAdapter,'orange_money'=>new OrangeMoneyAdapter,'maviance','campay'=>new ConfiguredJsonPaymentAdapter($p),default=>throw new InvalidArgumentException('Unsupported payment provider.')};}}
