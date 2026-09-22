<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PortalWorkspace extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id','user_id','portal','locale','preferences','last_seen_at'];
    protected function casts(): array { return ['preferences'=>'array','last_seen_at'=>'datetime']; }
}
