<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class SecurityFinding extends Model { use HasUuids; protected $fillable=['release_candidate_id','source','severity','title','description','status','cve','owner_id','due_at','resolved_at']; protected function casts():array{return['due_at'=>'datetime','resolved_at'=>'datetime'];} }
