<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class PrivilegedAccessGrant extends Model{use HasUuids;protected$fillable=['user_id','tenant_id','purpose','justification','approved_by','starts_at','expires_at','revoked_at','status','requested_by','revoked_by','revocation_reason','scope_hash','scope'];protected function casts():array{return['scope'=>'array','starts_at'=>'datetime','expires_at'=>'datetime','revoked_at'=>'datetime'];}}
