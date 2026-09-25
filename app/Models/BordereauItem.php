<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class BordereauItem extends Model { use HasUuids; protected $fillable=['bordereau_id','policy_id','transaction_type','premium_minor','commission_minor','currency','effective_at','source_type','source_id','amount_minor']; protected function casts():array{return['effective_at'=>'datetime'];} }
