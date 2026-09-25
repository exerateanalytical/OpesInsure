<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Read model over carrier_broker_agreement_products (writes: CarrierBrokerAgreementService::setProduct only). */
final class CarrierBrokerAgreementProductRecord extends Model
{
    use HasUuids;

    protected $table = 'carrier_broker_agreement_products';

    protected $guarded = ['*'];
}
