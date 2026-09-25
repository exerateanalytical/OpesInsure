<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CalendarException extends Model
{
    use HasUuids;

    public $timestamps = true;

    protected $table = 'calendar_exceptions';

    protected $guarded = [];

    protected $casts = ['date' => 'date'];
}
