<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
/** `secrets` is encrypted at rest (APP_KEY) and hidden from serialisation; never read back into a form or a log. */
final class PaymentProviderConnection extends Model{use HasUuids;protected$fillable=['tenant_id','provider','environment','status','credential_reference','capabilities','secrets','created_by','approved_by','approved_at'];protected$hidden=['secrets'];protected function casts():array{return['capabilities'=>'array','secrets'=>'encrypted:array','approved_at'=>'datetime'];}}
