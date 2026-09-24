<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class Document extends Model{use HasUuids;protected$fillable=['tenant_id','party_id','policy_id','category','storage_key','mime_type','size_bytes','sha256','scan_status','verification_status','ocr_data'];protected function casts():array{return['ocr_data'=>'array'];}public function tenant():BelongsTo{return$this->belongsTo(Tenant::class);}public function party():BelongsTo{return$this->belongsTo(Party::class);}}
