<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class ReleaseGateResult extends Model { use HasUuids; protected $fillable=['release_candidate_id','gate','status','evidence','evidence_hash','assessed_by','assessed_at']; protected function casts():array{return['evidence'=>'array','assessed_at'=>'datetime'];} }
