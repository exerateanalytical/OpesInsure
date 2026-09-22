<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class Courier extends Model { use HasUuids; protected $guarded=[]; protected $hidden=['phone_hash']; protected function casts(): array { return ['service_areas' => 'array']; } }
