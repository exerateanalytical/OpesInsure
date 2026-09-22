<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Role extends Model { use HasUuids; protected $fillable = ['tenant_id','code','permissions','is_system']; protected function casts(): array { return ['permissions'=>'array','is_system'=>'boolean']; } }
