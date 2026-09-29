<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Models\CarrierApiConnection;
use Illuminate\Http\Client\Response;

/**
 * One method per Activa operation used by OpesInsure (operation ids from docs/integrations/activa/operations_2026-09-29.json).
 * Thin: builds the path, calls ActivaGateway, decodes. Mapping of our records lives in ActivaPayloadMapper.
 */
final class ActivaApi
{
    public const NEW_CONTRACT = ['AUTO' => 'NewContractCMR', 'MRH' => 'NewContractMRHCMR', 'SANTE' => 'NewContractSanteCMR', 'IA' => 'NewContractIndiduelleAccidentCMR', 'VOYAGE' => 'NewContractVoyageCMR'];

    public const RENEW_CONTRACT = ['AUTO' => 'RenewContractCMR', 'MRH' => 'RenewContractMRHCMR', 'SANTE' => 'RenewContractSanteCMR', 'IA' => 'RenewContractIndividuelleAccidentCMR', 'VOYAGE' => 'RenewContractVoyageCMR'];

    public const PRICING_OPERATIONS = ['tarifpolice', 'tarifgarantie', 'tarifflotte', 'checkDommage', 'mattarif', 'commissionpolice', 'commissiongarantie', 'commissionflotte', 'CommissionGeneral'];

    public const PRICING_LOOKUPS = ['CotisationBaseAnnuelle' => 'codecate', 'categoriegarantiecaracteristique' => 'codecate', 'GetCaractPackage' => 'codematrix'];

    public function __construct(private readonly ActivaGateway $gateway) {}

    // ---- Travel (activa-cameroun-travel-api) -------------------------------------------------------------------

    /** POST /travel/quotes_requests → {quote_code, products[]} */
    public function travelQuote(CarrierApiConnection $c, array $body, array $subject = [], ?string $language = null): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::TRAVEL, 'getTravelQuote', 'POST', '/travel/quotes_requests',
            ['json' => $body, 'headers' => array_filter(['Accept-Language' => $language]), ...$subject]), ActivaServices::TRAVEL, 'getTravelQuote');
    }

    /** POST /travel/policies → 201 {policy_id, policy_number, status} */
    public function travelCreatePolicy(CarrierApiConnection $c, array $body, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::TRAVEL, 'createTravelPolicy', 'POST', '/travel/policies', ['json' => $body, ...$subject]),
            ActivaServices::TRAVEL, 'createTravelPolicy');
    }

    /** GET /travel/policies/{policyId} (optional header customer_email) */
    public function travelGetPolicy(CarrierApiConnection $c, string $policyId, ?string $customerEmail = null, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::TRAVEL, 'getTravelPolicy', 'GET', '/travel/policies/'.rawurlencode($policyId),
            ['headers' => array_filter(['customer_email' => $customerEmail]), ...$subject]), ActivaServices::TRAVEL, 'getTravelPolicy');
    }

    /** PATCH /travel/policies/{policyId} → {policy_id, policy_number, status} */
    public function travelUpdatePolicy(CarrierApiConnection $c, string $policyId, array $body, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::TRAVEL, 'updateTravelPolicy', 'PATCH', '/travel/policies/'.rawurlencode($policyId), ['json' => $body, ...$subject]),
            ActivaServices::TRAVEL, 'updateTravelPolicy');
    }

    /** POST /travel/policies/{policyId}/cancel {cancellation_reason} → {status, message} */
    public function travelCancelPolicy(CarrierApiConnection $c, string $policyId, string $reason, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::TRAVEL, 'cancelTravelPolicy', 'POST', '/travel/policies/'.rawurlencode($policyId).'/cancel',
            ['json' => ['cancellation_reason' => $reason], ...$subject]), ActivaServices::TRAVEL, 'cancelTravelPolicy');
    }

    /** GET /travel/policies/{policyId}/certificate → PDF bytes. @return array{0: string, 1: string} bytes, mime */
    public function travelCertificate(CarrierApiConnection $c, string $policyId, array $subject = []): array
    {
        $r = $this->gateway->send($c, ActivaServices::TRAVEL, 'getPolicyCertificate', 'GET', '/travel/policies/'.rawurlencode($policyId).'/certificate',
            ['accept' => 'application/pdf, application/json', ...$subject]);

        return $this->binary($r, ActivaServices::TRAVEL, 'getPolicyCertificate');
    }

    // ---- Souscription (souscription-cmr) --------------------------------------------------------------------------

    public function newContract(CarrierApiConnection $c, string $family, array $body, array $subject = []): array
    {
        $op = self::NEW_CONTRACT[$family] ?? throw ActivaException::make(ActivaException::MAPPING_REQUIRED, ActivaServices::SUBSCRIPTION, 'NewContract', null, "No Activa contract operation for family {$family}.");

        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, $op, 'POST', $this->v($c)."/SouscriptionCMR/{$op}", ['json' => $body, ...$subject]),
            ActivaServices::SUBSCRIPTION, $op, true);
    }

    public function renewContract(CarrierApiConnection $c, string $family, array $body, array $subject = []): array
    {
        $op = self::RENEW_CONTRACT[$family] ?? throw ActivaException::make(ActivaException::MAPPING_REQUIRED, ActivaServices::SUBSCRIPTION, 'RenewContract', null, "No Activa renewal operation for family {$family}.");

        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, $op, 'POST', $this->v($c)."/RenouvellementCMR/{$op}", ['json' => $body, ...$subject]),
            ActivaServices::SUBSCRIPTION, $op, true);
    }

    /** POST /api/v{v}/SouscriptionCMR/AttestationCMR/{idctr}?num= {codeinte, numeattestation, codtypdocument, dateeffe} */
    public function attestation(CarrierApiConnection $c, string $idctr, array $body, ?string $num = null, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'AttestationCMR', 'POST', $this->v($c).'/SouscriptionCMR/AttestationCMR/'.rawurlencode($idctr),
            ['json' => $body, 'query' => array_filter(['num' => $num]), ...$subject]), ActivaServices::SUBSCRIPTION, 'AttestationCMR', true);
    }

    /** POST /api/v{v}/SouscriptionCMR/AttestationFlotteCMR/{idctr} {codeinte, dateeffe, risques[]} */
    public function fleetAttestation(CarrierApiConnection $c, string $idctr, array $body, ?string $num = null, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'AttestationFlotteCMR', 'POST', $this->v($c).'/SouscriptionCMR/AttestationFlotteCMR/'.rawurlencode($idctr),
            ['json' => $body, 'query' => array_filter(['num' => $num]), ...$subject]), ActivaServices::SUBSCRIPTION, 'AttestationFlotteCMR', true);
    }

    /** GET /api/v{v}/SouscriptionCMR/Idctr/{idctr} → the contract as Activa holds it. */
    public function contract(CarrierApiConnection $c, string $idctr, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'Idctr', 'GET', $this->v($c).'/SouscriptionCMR/Idctr/'.rawurlencode($idctr), $subject),
            ActivaServices::SUBSCRIPTION, 'Idctr');
    }

    /** POST /api/v{v}/SouscriptionCMR/RechercheContratCMR?nomclient=&numepolice=&numimma= */
    public function searchContracts(CarrierApiConnection $c, array $query): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'RechercheContratCMR', 'POST', $this->v($c).'/SouscriptionCMR/RechercheContratCMR',
            ['query' => array_filter(array_intersect_key($query, array_flip(['nomclient', 'numepolice', 'numimma'])))]), ActivaServices::SUBSCRIPTION, 'RechercheContratCMR', true);
    }

    public function referentialData(CarrierApiConnection $c): mixed
    {
        return $this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'ReferentialData', 'GET', $this->v($c).'/SouscriptionCMR/ReferentialData')->json();
    }

    public function referentialDataByCategory(CarrierApiConnection $c, string $codecate): mixed
    {
        return $this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'ReferentialDataByCategorie', 'GET', $this->v($c).'/SouscriptionCMR/ReferentialDataByCategorie/'.rawurlencode($codecate))->json();
    }

    /** GET /api/Documents/contrat/{numeroContrat} → {succes, message, documents[{nomDocument, typeDocument, …}]} */
    public function contractDocuments(CarrierApiConnection $c, string $numeroContrat, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'Documents.contrat', 'GET', '/api/Documents/contrat/'.rawurlencode($numeroContrat), $subject),
            ActivaServices::SUBSCRIPTION, 'Documents.contrat');
    }

    /** @return array{0: string, 1: string} */
    public function downloadContractDocument(CarrierApiConnection $c, string $numeroContrat, string $nomDocument, array $subject = []): array
    {
        $r = $this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'Documents.download', 'GET',
            '/api/Documents/contrat/'.rawurlencode($numeroContrat).'/download/'.rawurlencode($nomDocument), ['accept' => 'application/pdf, application/octet-stream, application/json', ...$subject]);

        return $this->binary($r, ActivaServices::SUBSCRIPTION, 'Documents.download');
    }

    /** POST /api/v{v}/EncaissementCMR/Encaissement {encaissement: [...]} */
    public function recordPayment(CarrierApiConnection $c, array $rows, array $subject = []): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'Encaissement', 'POST', $this->v($c).'/EncaissementCMR/Encaissement',
            ['json' => ['encaissement' => array_values($rows)], ...$subject]), ActivaServices::SUBSCRIPTION, 'Encaissement', true);
    }

    public function payment(CarrierApiConnection $c, string $id): array
    {
        return $this->json($this->gateway->send($c, ActivaServices::SUBSCRIPTION, 'EncaissementCMR.get', 'GET', $this->v($c).'/EncaissementCMR/'.rawurlencode($id)),
            ActivaServices::SUBSCRIPTION, 'EncaissementCMR.get', true);
    }

    // ---- Tarifiktor (pricing) -------------------------------------------------------------------------------------

    /** POST /api/v{v}/Tarification/{operation} (tarifpolice, tarifgarantie, tarifflotte, …) */
    public function price(CarrierApiConnection $c, string $operation, array $body, array $subject = []): array
    {
        if (! in_array($operation, self::PRICING_OPERATIONS, true)) {
            throw new \InvalidArgumentException("Unknown Tarifiktor operation {$operation}.");
        }

        return $this->json($this->gateway->send($c, ActivaServices::PRICING, $operation, 'POST', $this->v($c)."/Tarification/{$operation}", ['json' => $body, ...$subject]),
            ActivaServices::PRICING, $operation, true);
    }

    /** GET /api/v{v}/Tarification/{lookup}?codecate= | ?codematrix= */
    public function pricingLookup(CarrierApiConnection $c, string $lookup, string $value): mixed
    {
        $param = self::PRICING_LOOKUPS[$lookup] ?? throw new \InvalidArgumentException("Unknown Tarifiktor lookup {$lookup}.");

        return $this->gateway->send($c, ActivaServices::PRICING, $lookup, 'GET', $this->v($c)."/Tarification/{$lookup}", ['query' => [$param => $value]])->json();
    }

    // ---- Docgenerator ---------------------------------------------------------------------------------------------

    /** POST /api/Report/GetPdfReportBase64CMR/{type_id} (contract body) → PDF. @return array{0: string, 1: string} */
    public function pdfReport(CarrierApiConnection $c, string $typeId, array $contract, array $subject = []): array
    {
        $r = $this->gateway->send($c, ActivaServices::DOCUMENTS, 'GetPdfReportBase64CMR', 'POST', '/api/Report/GetPdfReportBase64CMR/'.rawurlencode($typeId),
            ['json' => $contract, 'accept' => 'application/json, text/plain, application/pdf', ...$subject]);

        return $this->binary($r, ActivaServices::DOCUMENTS, 'GetPdfReportBase64CMR');
    }

    /** Best-effort extraction of Activa's contract identifiers from an (undocumented) SouscriptionCMR answer. @return array{idctr: ?string, policy_number: ?string} */
    public static function contractIds(array $response): array
    {
        $find = function (array $keys) use ($response): ?string {
            foreach ($keys as $k) {
                $v = data_get($response, $k);
                if ((is_string($v) && trim($v) !== '' && strtolower($v) !== 'string') || is_int($v)) {
                    return (string) $v;
                }
            }

            return null;
        };

        return [
            'idctr' => $find(['idctr', 'idCtr', 'IdCtr', 'data.idctr', 'data.idCtr', 'contrat.idctr', 'result.idctr', 'value', 'id']),
            'policy_number' => $find(['numepolice', 'policectr', 'data.numepolice', 'data.policectr', 'contrat.numepolice', 'numPolice']),
        ];
    }

    private function v(CarrierApiConnection $c): string
    {
        return '/api/v'.$this->gateway->apiVersion($c);
    }

    /** Decode a JSON answer; undocumented 200s may be empty or a bare value (then wrapped as ['value' => …]). */
    private function json(Response $r, string $service, string $operation, bool $lenient = false): array
    {
        $json = $r->json();
        if (is_array($json)) {
            if (($json['succes'] ?? $json['success'] ?? true) === false) {
                throw ActivaException::make(ActivaException::REJECTED, $service, $operation, $r->status(), 'Activa returned success=false.');
            }

            return $json;
        }
        if ($lenient || $r->status() === 204) {
            $body = trim($r->body(), " \"\n\r\t");

            return $body === '' ? [] : ['value' => is_scalar($json) ? $json : $body];
        }

        throw ActivaException::make(ActivaException::INVALID_RESPONSE, $service, $operation, $r->status());
    }

    /** PDF from a raw body, a base64 string, or a JSON wrapper holding base64. @return array{0: string, 1: string} */
    private function binary(Response $r, string $service, string $operation): array
    {
        $body = $r->body();
        if (str_starts_with($body, '%PDF')) {
            return [$body, 'application/pdf'];
        }
        $json = $r->json();
        $candidate = is_string($json) ? $json : (is_array($json) ? (string) (data_get($json, 'base64') ?? data_get($json, 'pdf') ?? data_get($json, 'data') ?? data_get($json, 'content') ?? data_get($json, 'fileContents') ?? '') : trim($body, " \"\n\r\t"));
        $decoded = base64_decode($candidate, true);
        if ($decoded !== false && str_starts_with($decoded, '%PDF')) {
            return [$decoded, 'application/pdf'];
        }

        throw ActivaException::make(ActivaException::INVALID_RESPONSE, $service, $operation, $r->status(), 'The answer is not a PDF.');
    }
}
