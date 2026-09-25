<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Models\{Claim, Policy};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SSR §30 failure experience: every known failure condition has its own
 * explicit state (EN/FR copy in resources/lang/{en,fr}/web_experience.php),
 * never a generic "Something went wrong". Values reuse the API problem codes
 * in App\Interfaces\Http\Errors\ErrorCode where one exists
 * (PAYMENT_OK_ISSUANCE_FAILED, STALE_RECORD, DUPLICATE_SUBMISSION).
 */
enum FailureState: string
{
    case PaymentOkIssuanceFailed = 'PAYMENT_OK_ISSUANCE_FAILED';
    case IssuedDocumentFailed = 'ISSUED_DOCUMENT_FAILED';
    case ClaimPaymentFailed = 'CLAIM_PAYMENT_FAILED';
    case ProviderSettlementFailed = 'PROVIDER_SETTLEMENT_FAILED';
    case CarrierApiDown = 'CARRIER_API_DOWN';
    case ReinsuranceApiDown = 'REINSURANCE_API_DOWN';
    case StaleRecord = 'STALE_RECORD';
    case DuplicateSubmission = 'DUPLICATE_SUBMISSION';
    case PermissionDenied = 'PERMISSION_DENIED';
    case NetworkFailure = 'NETWORK_FAILURE';

    public function title(): string
    {
        return __('web_experience.failure.'.$this->value.'.title');
    }

    public function message(): string
    {
        return __('web_experience.failure.'.$this->value.'.message');
    }

    public function tone(): string
    {
        return match ($this) {
            self::StaleRecord, self::DuplicateSubmission, self::CarrierApiDown, self::ReinsuranceApiDown, self::NetworkFailure => 'warning',
            default => 'danger',
        };
    }

    /**
     * Known failure states of a record, derived only from persisted facts.
     *
     * @return list<self>
     */
    public static function detect(Model $record): array
    {
        $states = [];
        if ($record instanceof Policy) {
            $paid = $record->payment_intent_id !== null
                && DB::table('payment_intents')->where('id', $record->payment_intent_id)->where('status', 'SUCCEEDED')->exists();
            // PolicyStateMachine: PAID_PENDING_ISSUANCE; a REJECTED issuance request means paid but not issued.
            if ($paid && (string) $record->status === 'PAID_PENDING_ISSUANCE'
                && DB::table('policy_issuance_requests')->where('proposal_id', $record->proposal_id)->where('status', 'REJECTED')->exists()) {
                $states[] = self::PaymentOkIssuanceFailed;
            }
            if ((string) $record->status === 'ACTIVE' && DB::table('documents')->where('policy_id', $record->getKey())->where('status', 'FAILED')->exists()) {
                $states[] = self::IssuedDocumentFailed;
            }
        }
        if ($record instanceof Claim && $record->payments()->where('status', 'FAILED')->exists()) {
            $states[] = self::ClaimPaymentFailed;
        }

        return $states;
    }
}
