<?php

declare(strict_types=1);

namespace App\Application\Cases\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DiaryEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'diary_entries';

    protected $guarded = [];

    protected $casts = ['follow_up_at' => 'datetime', 'follow_up_notified_at' => 'datetime', 'created_at' => 'datetime'];
}
