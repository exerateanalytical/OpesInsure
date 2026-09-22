<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class ReleaseCandidate extends Model { use HasUuids; protected $fillable=['version','commit_sha','environment','status','created_by','approved_by','approved_at','released_at','lock_version']; protected function casts():array{return['approved_at'=>'datetime','released_at'=>'datetime'];} }
