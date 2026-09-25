<?php

declare(strict_types=1);

namespace App\Application\Finance\Clearing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A provider-succeeded payment included in a clearing batch (each payment clears once). */
final class ClearingItem extends Model
{
    use HasUuids;

    protected $table = 'mobile_money_clearing_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
