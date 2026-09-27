<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only row of a register table that has no Eloquent model of its own (quote requests, referrals,
 * co/reinsurance, KYC, cashier sessions, FX rates). Used only by App\Filament\Shared\Pages\RegisterPage,
 * which sets the table per query; it is never saved.
 */
final class RegisterRow extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public static function on_(string $table): \Illuminate\Database\Eloquent\Builder
    {
        return (new self)->setTable($table)->newQuery();
    }

    public function save(array $options = []): bool
    {
        return false;
    }
}
