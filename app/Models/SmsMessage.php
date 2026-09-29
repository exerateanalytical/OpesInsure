<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** SMS delivery log row. The destination is only ever stored masked (+2376*****12) and hashed; the body is never stored. */
final class SmsMessage extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
}
