<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class CanonicalEventSchema extends Model
{
    use HasUuids;
    protected $fillable = ['event_name','version','status','description','json_schema','example_payload','privacy_classification','deprecated_at'];
    protected function casts(): array { return ['json_schema'=>'array','example_payload'=>'array','deprecated_at'=>'datetime']; }
}
