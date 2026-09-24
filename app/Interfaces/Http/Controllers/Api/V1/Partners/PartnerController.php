<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Partners;
use App\Application\Audit\AuditWriter;use App\Application\Customers\PartyService;use App\Application\Partners\LicensingService;use App\Models\Partner;use App\Models\PartnerLicence;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use App\Application\Identity\PartyResolver;use App\Application\Partners\PartnerStatusService;use App\Models\User;use Illuminate\Validation\ValidationException;
final class PartnerController
{
public function index(Request$r):JsonResponse{$q=Partner::with(['party','licences'])->where(fn($x)=>$x->whereNull('tenant_id')->orWhere('tenant_id',app(\App\Domain\Tenancy\TenantContext::class)->id()));if($r->filled('type'))$q->where('type',$r->string('type'));return response()->json(['data'=>$q->latest()->paginate(25)]);}
/**
 * Registers a partner. When it is for an existing platform user (user_id, or
 * a user already holding the given phone) the partner is attached to THAT
 * user's own party, never a second party, because PartyResolver /
 * AgentPartnerResolver find a user's partner through users.party_id.
 * New partners stay PENDING until licence verification or an admin status
 * change (POST partners/{partner}/status, or the Filament action) activates them.
 */
public function store(Request$r,PartyService$parties,AuditWriter$audit):JsonResponse
{
    $d=$r->validate(['type'=>'required|in:AGENT,BROKER,CARRIER','display_name'=>'required|string|max:160','phone_e164'=>['nullable','regex:/^\+[1-9]\d{7,14}$/'],'email'=>'nullable|email|max:190','registration_number'=>'nullable|string|max:100','user_id'=>'nullable|uuid|exists:users,id']);
    $p=DB::transaction(function()use($d,$parties,$audit){
        $user=isset($d['user_id'])?User::find($d['user_id']):(!empty($d['phone_e164'])?User::where('phone_e164',$d['phone_e164'])->first():null);
        if($user){
            $party=app(PartyResolver::class)->forUser($user);
            if(!$party){
                $phoneTaken=$user->phone_e164&&DB::table('party_contacts')->where(['type'=>'PHONE','normalized_value'=>$user->phone_e164])->exists();
                $party=$parties->create(['type'=>$d['type']==='AGENT'?'PERSON':'ORGANIZATION','display_name'=>$d['display_name'],'registration_number'=>$d['registration_number']??null,'phone_e164'=>$phoneTaken?null:$user->phone_e164]);
            }
            if($user->party_id!==$party->id)$user->forceFill(['party_id'=>$party->id])->save();
            if(Partner::where('party_id',$party->id)->exists())throw ValidationException::withMessages(['user_id'=>'This user is already registered as a partner.']);
        }else{
            $party=$parties->create([...$d,'type'=>$d['type']==='AGENT'?'PERSON':'ORGANIZATION']);
        }
        $p=Partner::create(['tenant_id'=>app(\App\Domain\Tenancy\TenantContext::class)->id(),'party_id'=>$party->id,'type'=>$d['type'],'status'=>'PENDING','compliance'=>[]]);
        $audit->record('partner.created','partner',$p->id,['type'=>$p->type,'user_id'=>$user?->id]);
        return$p;
    });
    return response()->json(['data'=>$p->load('party')],201);
}
/** Admin activation / suspension of a partner. */
public function changeStatus(Request$r,Partner$partner,PartnerStatusService$s):JsonResponse{$this->authorize($r,$partner);$d=$r->validate(['status'=>'required|in:ACTIVE,SUSPENDED,REJECTED,PENDING','notes'=>'required|string|min:5|max:2000']);return response()->json(['data'=>$s->transition($partner,$d['status'],$d['notes'],$r->user())]);}
public function show(Request$r,Partner$partner):JsonResponse{$this->authorize($r,$partner);return response()->json(['data'=>$partner->load('party.contacts','licences.verifier')]);}
public function addLicence(Request$r,Partner$partner,LicensingService$s):JsonResponse{$this->authorize($r,$partner);$d=$r->validate(['authority'=>'required|string|max:64','licence_type'=>'required|string|max:64','licence_number'=>'required|string|max:100','issued_on'=>'nullable|date','expires_on'=>'nullable|date|after:issued_on','evidence_document_id'=>'nullable|uuid|exists:documents,id']);if(isset($d['evidence_document_id']))abort_unless(DB::table('documents')->where('id',$d['evidence_document_id'])->where(fn($q)=>$q->whereNull('tenant_id')->orWhere('tenant_id',app(\App\Domain\Tenancy\TenantContext::class)->id()))->exists(),404);return response()->json(['data'=>$s->submit($partner,$d)],201);}
public function verifyLicence(Request$r,Partner$partner,PartnerLicence$licence,LicensingService$s):JsonResponse{$this->authorize($r,$partner);abort_unless($licence->partner_id===$partner->id,404);$d=$r->validate(['decision'=>'required|in:VERIFIED,REJECTED','notes'=>'required|string|min:20|max:2000']);return response()->json(['data'=>$s->decide($licence,$d['decision'],$d['notes'],$r->user())]);}
private function authorize(Request$r,Partner$p):void{$global=$r->user()->memberships()->where('status','ACTIVE')->whereIn('role_code',['SYSTEM_ADMIN','PLATFORM_ADMIN','COMPLIANCE_ADMIN'])->exists();abort_unless($global||$p->tenant_id===app(\App\Domain\Tenancy\TenantContext::class)->id(),404);}
}
