<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;final class JournalLineRecord extends Model{use HasUuids;protected$table='journal_lines';protected$guarded=[];protected function casts():array{return['dimensions'=>'array'];}}
