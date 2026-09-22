<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class SettlementBatch extends Model { use HasUuids; protected $fillable=['tenant_id','carrier_id','settlement_number','period_start','period_end','net_amount_minor','currency','status','prepared_by','approved_by','approved_at','idempotency_key','content_hash','submitted_at','paid_at','bank_reference','failure_reason','reversed_by']; protected function casts():array{return['approved_at'=>'datetime','submitted_at'=>'datetime','paid_at'=>'datetime'];} }
