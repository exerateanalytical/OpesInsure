<?php
namespace App\Application\Ledger;

use App\Domain\Ledger\Journal; use App\Domain\Ledger\JournalLine; use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;

final class LedgerService
{
    public function post(?string $tenantId,string $referenceType,string $referenceId,string $currency,array $lines,string $correlationId):string
    {
        $domainLines=array_map(fn($x)=>new JournalLine($x['account_id'],(int)($x['debit_minor']??0),(int)($x['credit_minor']??0)),$lines);new Journal($currency,$domainLines);$id=(string)Str::uuid();
        DB::transaction(function()use($id,$tenantId,$referenceType,$referenceId,$currency,$lines,$correlationId){DB::table('journals')->insert(['id'=>$id,'tenant_id'=>$tenantId,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'currency'=>$currency,'status'=>'POSTED','correlation_id'=>$correlationId,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);foreach($lines as $line)DB::table('journal_lines')->insert(['id'=>(string)Str::uuid(),'journal_id'=>$id,'account_id'=>$line['account_id'],'debit_minor'=>$line['debit_minor']??0,'credit_minor'=>$line['credit_minor']??0,'dimensions'=>json_encode($line['dimensions']??[]),'created_at'=>now(),'updated_at'=>now()]);});return $id;
    }
    /** Batch 10-7 REQ-ACC-002: the only way a MANUAL journal becomes POSTED (APPROVED -> POSTED); lines are never rewritten. */
    public function postApproved(string $journalId,?string $actorId):void
    {
        DB::transaction(function()use($journalId,$actorId){$j=DB::table('journals')->where('id',$journalId)->lockForUpdate()->first();abort_unless($j&&$j->status==='APPROVED',409,'Only an approved journal can be posted.');$date=$j->journal_date??now()->toDateString();if(class_exists(\App\Application\Ledger\Periods\PeriodGuard::class))app(\App\Application\Ledger\Periods\PeriodGuard::class)->assertOpen($j->tenant_id,$date);DB::table('journals')->where('id',$journalId)->update(['status'=>'POSTED','posted_by'=>$actorId,'posted_at'=>now(),'updated_at'=>now()]);});
    }
    public function reverse(string $journalId,string $correlationId):string
    {
        return DB::transaction(function()use($journalId,$correlationId){$original=DB::table('journals')->where('id',$journalId)->where('status','POSTED')->lockForUpdate()->first();abort_unless($original,409,'Only a posted journal can be reversed.');$lines=DB::table('journal_lines')->where('journal_id',$journalId)->get()->map(fn($x)=>['account_id'=>$x->account_id,'debit_minor'=>$x->credit_minor,'credit_minor'=>$x->debit_minor,'dimensions'=>json_decode($x->dimensions,true)])->all();$reversal=$this->post($original->tenant_id,'JOURNAL_REVERSAL',$journalId,$original->currency,$lines,$correlationId);DB::table('journals')->where('id',$journalId)->update(['status'=>'REVERSED','reversed_at'=>now(),'updated_at'=>now()]);DB::table('journals')->where('id',$reversal)->update(['reverses_journal_id'=>$journalId]);return $reversal;});
    }
}
