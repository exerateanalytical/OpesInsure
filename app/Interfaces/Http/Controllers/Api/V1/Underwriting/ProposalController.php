<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Underwriting;
use App\Application\Identity\OwnershipScope;
use App\Application\Underwriting\{ProposalMachine,ProposalService,UnderwritingService};
use App\Domain\Tenancy\TenantContext;
use App\Models\{Document,Proposal,QuoteOffer,Tenant,UnderwritingCase,UnderwritingReferralTask,User};
use Illuminate\Http\{JsonResponse,Request};
final class ProposalController
{
 public function __construct(private OwnershipScope $own) {}
 /* Customers only ever resolve their own proposals (audit A1). */
 private function proposal(string$id):Proposal{return $this->own->apply(Proposal::where('tenant_id',app(TenantContext::class)->id()),request()->user())->findOrFail($id);}
 private function underwritingCase(string$id):UnderwritingCase{return UnderwritingCase::where('tenant_id',app(TenantContext::class)->id())->findOrFail($id);}
 private function referral(string$id):UnderwritingReferralTask{return UnderwritingReferralTask::whereHas('underwritingCase',fn($q)=>$q->where('tenant_id',app(TenantContext::class)->id()))->findOrFail($id);}
 public function store(Request$r,ProposalService$s,TenantContext$c):JsonResponse{$d=$r->validate(['quote_offer_id'=>'required|uuid|exists:quote_offers,id','party_id'=>'required|uuid|exists:parties,id']);$this->own->assertOwnParty($r->user(),$d['party_id']);$offer=$this->own->applyVia(QuoteOffer::query(),$r->user(),'quote')->findOrFail($d['quote_offer_id']);return response()->json(['data'=>$s->create(Tenant::findOrFail($c->id()),$offer,$d,$r->user())],201);}
 public function show(string$proposal,ProposalService$s):JsonResponse{$p=$this->proposal($proposal)->load(['offer.product','disclosureSchema','disclosureResponse','documents.document','underwritingCase.referrals','underwritingCase.decisions','payments']);$data=$p->toArray();
  /* REQ-PRP-002/003 (additive): server-listed requirements and questions; disclosure_schema kept for app 1.3.0 even when the questions come from a PROPOSAL question set. */
  $data['disclosure_schema']=$data['disclosure_schema']??['questions'=>$s->questions($p)];$data['questions']=$s->questions($p);$data['required_documents']=$s->requiredDocuments($p);$data['blueprint_state']=ProposalMachine::blueprintState($p->status);$data['available_transitions']=$s->availableEvents($p,request()->user());
  return response()->json(['data'=>$data]);}
 public function answer(Request$r,string$proposal,ProposalService$s):JsonResponse{$d=$r->validate(['answers'=>'required|array']);return response()->json(['data'=>$s->answer($this->proposal($proposal),$d['answers'],$r->user())]);}
 public function attest(Request$r,string$proposal,ProposalService$s):JsonResponse{return response()->json(['data'=>$s->attest($this->proposal($proposal),$r->user())]);}
 public function attachDocument(Request$r,string$proposal,ProposalService$s):JsonResponse{$p=$this->proposal($proposal);$d=$r->validate(['document_id'=>'required|uuid|exists:documents,id','requirement_code'=>'required|string|max:64']);return response()->json(['data'=>$s->attachDocument($p,$this->own->apply(Document::query(),$r->user())->findOrFail($d['document_id']),$d['requirement_code'])],201);}
 public function reviewDocument(Request$r,string$proposal,string$document,ProposalService$s):JsonResponse{$d=$r->validate(['decision'=>'required|in:VERIFIED,REJECTED','notes'=>'required|string|max:2000']);return response()->json(['data'=>$s->verifyDocument($this->proposal($proposal),Document::findOrFail($document),$d['decision'],$d['notes'],$r->user())]);}
 public function submit(Request$r,string$proposal,ProposalService$s):JsonResponse{return response()->json(['data'=>$s->submit($this->proposal($proposal),$r->user())],202);}
 public function assign(Request$r,string$case,UnderwritingService$s):JsonResponse{$d=$r->validate(['assignee_id'=>'required|uuid|exists:users,id']);return response()->json(['data'=>$s->assign($this->underwritingCase($case),User::findOrFail($d['assignee_id']),$r->user())]);}
 public function resolveReferral(Request$r,string$referral,UnderwritingService$s):JsonResponse{$d=$r->validate(['notes'=>'required|string|min:20|max:4000']);return response()->json(['data'=>$s->resolveReferral($this->referral($referral),$d['notes'],$r->user())]);}
 public function decide(Request$r,string$case,UnderwritingService$s):JsonResponse{$d=$r->validate(['decision'=>'required|in:APPROVED,COUNTEROFFERED,DECLINED','reason_code'=>'required|string|max:64','notes'=>'required|string|min:20|max:4000','conditions'=>'sometimes|array']);return response()->json(['data'=>$s->decide($this->underwritingCase($case),$d,$r->user())]);}
}
