<?php
declare(strict_types=1);
namespace App\Application\Events;
use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;
final class OutboxWriter{public function record(string$event,string$aggregateType,string$aggregateId,array$payload,array$metadata=[]):void{DB::table('outbox_messages')->insert(['id'=>(string)Str::uuid(),'event_name'=>$event,'event_version'=>1,'aggregate_type'=>$aggregateType,'aggregate_id'=>$aggregateId,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'occurred_at'=>now(),'attempts'=>0]);}}
