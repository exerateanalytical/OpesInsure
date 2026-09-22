<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class CommissionAccrual extends Model { use HasUuids; protected $fillable=['tenant_id','policy_id','partner_id','rule_version','rule_version_id','amount_minor','currency','status','vests_at','vested_minor','paid_minor','clawed_back_minor','source_type','source_id','idempotency_key','available_at']; protected function casts():array{return['vests_at'=>'datetime','available_at'=>'datetime'];} }
