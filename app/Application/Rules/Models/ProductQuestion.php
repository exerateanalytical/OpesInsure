<?php

declare(strict_types=1);

namespace App\Application\Rules\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProductQuestion extends Model
{
    use HasUuids;

    protected $table = 'product_questions';

    protected $guarded = [];

    protected $casts = ['display_order' => 'integer', 'required' => 'boolean', 'risk_factor' => 'boolean', 'options' => 'array', 'validation' => 'array', 'visibility' => 'array', 'rendered_field' => 'array'];
}
