<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class NotificationDelivery extends Model { use HasUuids; protected $guarded=[]; protected $hidden=['destination_hash']; protected function casts():array{return ['payload'=>'array','next_attempt_at'=>'datetime','sent_at'=>'datetime'];} }
