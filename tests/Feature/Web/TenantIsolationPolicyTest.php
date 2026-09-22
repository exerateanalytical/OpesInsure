<?php
use App\Models\Tenant;use App\Models\TenantMembership;use App\Models\User;use App\Policies\TenantPolicy;use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
test('broker staff cannot administer organizations',function(){$u=User::factory()->create();$t=Tenant::create(['type'=>'BROKER','legal_name'=>'Broker','slug'=>'broker','status'=>'ACTIVE','country_code'=>'CM','currency'=>'XAF','primary_locale'=>'en','settings'=>[]]);TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role_code'=>'BROKER_STAFF','status'=>'ACTIVE']);expect((new TenantPolicy)->viewAny($u))->toBeFalse()->and((new TenantPolicy)->create($u))->toBeFalse();});
