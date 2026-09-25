<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Ledger;

use App\Application\Audit\AuditWriter; use App\Application\Ledger\LedgerService; use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;

final class LedgerController
{
    public function accounts(){return response()->json(['data'=>DB::table('ledger_accounts')->where(fn($q)=>$q->whereNull('tenant_id')->orWhere('tenant_id',app(\App\Domain\Tenancy\TenantContext::class)->id()))->orderBy('code')->get()]);}
    public function journal(string $journal){$row=DB::table('journals')->where('id',$journal)->first();abort_unless($row&&($row->tenant_id===null||$row->tenant_id===app(\App\Domain\Tenancy\TenantContext::class)->id()),404);return response()->json(['data'=>['journal'=>$row,'lines'=>DB::table('journal_lines')->where('journal_id',$journal)->get()]]);}
    /**
     * Deprecated alias (owner decision D10): no longer posts immediately — it bypassed maker-checker.
     * Same request shape; creates a DRAFT manual journal that must go through
     * /api/v1/ledger/manual-journals/{id}/validate|approve|post.
     */
    public function manual(Request $r,\App\Application\Ledger\Journals\ManualJournalService $journals){$d=$r->validate(['reference_type'=>'required|string|max:64','reference_id'=>'required|uuid','currency'=>'required|in:XAF','reason_code'=>'required|string|max:64','description'=>'nullable|string|max:500','journal_date'=>'nullable|date_format:Y-m-d','lines'=>'required|array|min:2|max:50','lines.*.account_id'=>'required|uuid|exists:ledger_accounts,id','lines.*.debit_minor'=>'required_without:lines.*.credit_minor|integer|min:0','lines.*.credit_minor'=>'required_without:lines.*.debit_minor|integer|min:0','lines.*.dimensions'=>'nullable|array']);$id=$journals->createDraft(app(\App\Domain\Tenancy\TenantContext::class)->id(),$r->user()->id,$d,$r->header('X-Request-Id',(string)Str::uuid()));return response()->json(['data'=>['id'=>$id,'status'=>'DRAFT','next'=>"/api/v1/ledger/manual-journals/{$id}/validate"],'meta'=>['deprecated'=>true,'successor'=>'/api/v1/ledger/manual-journals']],201)->withHeaders(['Deprecation'=>'true','Link'=>'</api/v1/ledger/manual-journals>; rel="successor-version"']);}
    public function reverse(Request $r,string $journal,LedgerService $ledger,AuditWriter $audit){$d=$r->validate(['reason_code'=>'required|string|max:64','notes'=>'required|string|min:20|max:2000']);$id=$ledger->reverse($journal,$r->header('X-Request-Id',(string)Str::uuid()));$audit->record('ledger.reversed','journal',$journal,['reversal_journal_id'=>$id],$d['reason_code']);return response()->json(['data'=>['original_id'=>$journal,'reversal_id'=>$id,'status'=>'REVERSED']]);}
}
