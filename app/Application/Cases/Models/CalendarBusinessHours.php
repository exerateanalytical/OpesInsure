<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CalendarBusinessHours extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'calendar_business_hours';

    protected $guarded = [];

    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date'];
}
