<?php

declare(strict_types=1);

namespace App\Models\Directory;

use Illuminate\Database\Eloquent\Model;

/** Admin-controlled EN/FR display label of an institution verification status. */
final class InstitutionVerificationLabel extends Model
{
    public const DEFAULTS = [
        'VERIFIED' => ['Verified', 'Vérifié'],
        'PARTIALLY_VERIFIED' => ['Partially verified', 'Partiellement vérifié'],
        'VERIFIED_HQ_BRANCHES_PENDING' => ['HQ verified, branches pending', 'Siège vérifié, agences en attente'],
        'VERIFIED_NETWORK_SHARED_WITH_GROUP' => ['Verified (group network)', 'Vérifié (réseau du groupe)'],
    ];

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $fillable = ['code', 'label_en', 'label_fr', 'updated_by'];

    /** @return array<string, array{en: string, fr: string}> */
    public static function map(): array
    {
        $rows = self::query()->get()->keyBy('code');

        return collect(self::DEFAULTS)->map(fn ($d, $code) => [
            'en' => $rows->get($code)?->label_en ?? $d[0],
            'fr' => $rows->get($code)?->label_fr ?? $d[1],
        ])->all();
    }
}
