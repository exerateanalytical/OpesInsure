<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class FulfilmentOrder extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['delivery_address'=>'array','proof_of_delivery'=>'array','sla_due_at'=>'datetime','delivered_at'=>'datetime'];} public function policy():BelongsTo{return $this->belongsTo(Policy::class);} public function courier():BelongsTo{return $this->belongsTo(Courier::class);} }
