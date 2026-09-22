<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class SupportTicket extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['sla_due_at'=>'datetime','acknowledged_at'=>'datetime','resolved_at'=>'datetime','closed_at'=>'datetime'];} }
