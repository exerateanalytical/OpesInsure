<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Application\Audit\AuditWriter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenantLifecycleService
{
    private const TRANSITIONS = ['PENDING'=>['ACTIVE','CLOSED'],'ACTIVE'=>['SUSPENDED','CLOSED'],'SUSPENDED'=>['ACTIVE','CLOSED'],'CLOSED'=>[]];
    public function __construct(private readonly AuditWriter $audit) {}

    public function transition(Tenant $tenant, string $to, string $reason, string $notes, User $actor): Tenant
    {
        $from = $tenant->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) throw ValidationException::withMessages(['status'=>__('wave0.invalid_transition')]);
        return DB::transaction(function () use ($tenant,$from,$to,$reason,$notes,$actor): Tenant {
            $tenant->update(['status'=>$to,'activated_at'=>$to==='ACTIVE' ? now() : $tenant->activated_at]);
            DB::table('tenant_status_history')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'from_status'=>$from,'to_status'=>$to,'reason_code'=>$reason,'notes'=>$notes,'actor_id'=>$actor->id,'occurred_at'=>now()]);
            $this->audit->record('tenant.status.changed','tenant',$tenant->id,['from'=>$from,'to'=>$to,'notes'=>$notes],$reason);
            return $tenant->refresh();
        });
    }
}
