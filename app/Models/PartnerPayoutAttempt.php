<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class PartnerPayoutAttempt extends Model { use HasUuids; protected $fillable=['partner_payout_request_id','attempt_number','provider','provider_reference','status','request_hash','failure_code','failure_message','attempted_at']; protected function casts():array{return['attempted_at'=>'datetime'];} }
