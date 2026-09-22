<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DashboardSnapshot extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id','portal','period_key','metrics','integrity_hash','generated_at'];
    protected function casts(): array { return ['metrics'=>'array','generated_at'=>'datetime']; }
}
