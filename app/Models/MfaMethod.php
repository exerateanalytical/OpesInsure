<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfaMethod extends Model
{
    use HasUuids;
    protected $fillable = ['user_id','type','secret_encrypted','destination_masked','verified_at','disabled_at'];
    protected $hidden = ['secret_encrypted'];
    protected function casts(): array { return ['secret_encrypted'=>'encrypted','verified_at'=>'datetime','disabled_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
