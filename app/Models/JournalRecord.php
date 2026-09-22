<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\HasMany;
final class JournalRecord extends Model{use HasUuids;protected$table='journals';protected$guarded=[];protected function casts():array{return['posted_at'=>'datetime'];}public function lines():HasMany{return$this->hasMany(JournalLineRecord::class,'journal_id');}}
