<?php

declare(strict_types=1);

namespace App\Application\MarketData;

use App\Application\Audit\AuditWriter;
use App\Application\DataReadiness\DataStatus;
use App\Application\DataReadiness\NonProductionDataException;
use App\Application\DataReadiness\ProductionUseGuard;
use App\Models\CommissionRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gap closure 01 production gates (Insurance Market, Product, Agreement & Commission Master). Pack statuses
 * PENDING_PRIVATE_SOURCE / PENDING_OFFICIAL_IMPORT / PENDING_VERIFICATION / CONFIG_REQUIRED are gates, not blanks:
 *  - a broker–insurer agreement is activated in production only with its signed source document, verified by a
 *    second person (verifyAgreementSource);
 *  - a commission rule is approved in production only when it cites its source agreement or document;
 *  - product publication without a CIMA branch authorization is already refused by CimaPublicationGuard
 *    (BLOCK_NEW_PRODUCT_PUBLICATION_IF_REQUIRED_BRANCH_AUTHORIZATION_UNKNOWN).
 * Enforcement follows ProductionUseGuard (real production host with demo mode off, or data_readiness.enforce).
 */
final class MarketDataGates
{
    public const FILE = 'data/gap_closure_2026/01_insurance_market_products_contracts.json';

    /** Pack status → Workflow Data Master vocabulary (kept local: DataStatus stored values are never renamed). */
    public const PACK_STATUS = [
        'VERIFIED_PUBLIC_SOURCE' => DataStatus::VERIFIED, 'PLATFORM_NORMALIZED' => DataStatus::PLATFORM_NORMALIZED,
        'CONFIG_REQUIRED' => DataStatus::CONFIG_REQUIRED, 'PENDING_PRIVATE_SOURCE' => DataStatus::PENDING_SOURCE,
        'PENDING_OFFICIAL_IMPORT' => DataStatus::PENDING_SOURCE, 'PENDING_VERIFICATION' => DataStatus::UNVERIFIED, 'RETIRED' => DataStatus::RETIRED,
    ];

    public function __construct(private readonly AuditWriter $audit) {}

    public static function packStatus(?string $status): string
    {
        return self::PACK_STATUS[strtoupper((string) $status)] ?? DataStatus::normalize($status);
    }

    /** @return array<string, mixed> */
    public static function pack(): array
    {
        static $pack;

        return $pack ??= (array) json_decode((string) file_get_contents(database_path(self::FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function assertAgreementActivatable(object $agreement): void
    {
        if ((bool) $agreement->is_demo || ! ProductionUseGuard::enforced()) {
            return;
        }
        if (blank($agreement->source_document ?? null) && blank($agreement->source_document_id ?? null)) {
            throw ValidationException::withMessages(['source_document' => ['Attach the signed broker–insurer agreement before activation (PENDING_PRIVATE_SOURCE).']]);
        }
        ProductionUseGuard::assertUsable('broker_insurer_agreements', self::packStatus($agreement->data_status ?? null), (string) $agreement->agreement_number,
            'Verify the agreement source document first.');
    }

    /** Checker confirms the signed agreement document: data_status → VERIFIED (maker-checker against the agreement author). */
    public function verifyAgreementSource(string $agreementId, User $checker, string $sourceDocument, ?string $note = null): object
    {
        $a = DB::table('carrier_broker_agreements')->find($agreementId) ?? throw ValidationException::withMessages(['agreement' => ['Unknown agreement.']]);
        if ($a->created_by !== null && $a->created_by === $checker->id) {
            throw ValidationException::withMessages(['actor' => ['Maker-checker: the author cannot verify their own agreement source.']]);
        }
        if (trim($sourceDocument) === '') {
            throw ValidationException::withMessages(['source_document' => ['The signed agreement document is required.']]);
        }
        DB::table('carrier_broker_agreements')->where('id', $agreementId)->update(['source_document' => $sourceDocument, 'data_status' => 'VERIFIED', 'updated_at' => now()]);
        $this->audit->recordChange('carrier_broker_agreement.source_verified', 'carrier_broker_agreement', $agreementId,
            ['data_status' => $a->data_status, 'source_document' => $a->source_document], ['data_status' => 'VERIFIED', 'source_document' => $sourceDocument], $note ?? 'Agreement source verified');

        return DB::table('carrier_broker_agreements')->find($agreementId);
    }

    public function assertCommissionRuleApprovable(CommissionRuleVersion $rule): void
    {
        if (! ProductionUseGuard::enforced()) {
            return;
        }
        if ($rule->agreement_id === null && blank($rule->source_document)) {
            throw new NonProductionDataException('commission', DataStatus::PENDING_SOURCE,
                'commission rule '.$rule->id.' has no source agreement or document (PENDING_PRIVATE_SOURCE): it cannot be approved for production.');
        }
    }
}
