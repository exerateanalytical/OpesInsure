<?php
use App\Models\{Tenant,TenantMembership,User};use Illuminate\Foundation\Testing\RefreshDatabase;use Illuminate\Support\Facades\Gate;
uses(RefreshDatabase::class);
/** UI QA 2026-09-27: claims staff saw Policies but got 403 on the record; list and detail must agree. */
test('a claims manager can list and open policies of their own tenant, read-only',function(){
 $t=Tenant::create(['type'=>'CARRIER','legal_name'=>'Carrier X','slug'=>'carrier-cm-pol','status'=>'ACTIVE','country_code'=>'CM','currency'=>'XAF','primary_locale'=>'en','settings'=>[]]);
 $u=User::factory()->create(['status'=>'ACTIVE']);TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role_code'=>'CLAIMS_MANAGER','status'=>'ACTIVE']);
 $m=TenantMembership::where('user_id',$u->id)->first();$m->roles()->attach(\App\Models\Role::create(['tenant_id'=>$t->id,'code'=>'CLAIMS_MANAGER-t','permissions'=>\App\Application\Identity\RoleCatalogue::defaultPermissions('CLAIMS_MANAGER'),'is_system'=>false])->id);
 $this->actingAs($u);app(\App\Domain\Tenancy\TenantContext::class)->set($t->id);
 expect(Gate::forUser($u)->allows('viewAny',\App\Models\Policy::class))->toBeTrue()
  ->and(Gate::forUser($u)->allows('view',new \App\Models\Policy(['tenant_id'=>$t->id])))->toBeTrue()
  ->and(Gate::forUser($u)->allows('view',new \App\Models\Policy(['tenant_id'=>(string)\Illuminate\Support\Str::uuid()])))->toBeFalse()
  ->and(Gate::forUser($u)->allows('update',new \App\Models\Policy(['tenant_id'=>$t->id])))->toBeFalse();
});
