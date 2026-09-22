<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class RecoveryExercise extends Model { use HasUuids; protected $fillable=['environment','exercise_type','status','target_rto_minutes','target_rpo_minutes','actual_rto_minutes','actual_rpo_minutes','evidence','conducted_by','conducted_at']; protected function casts():array{return['evidence'=>'array','conducted_at'=>'datetime'];} }
