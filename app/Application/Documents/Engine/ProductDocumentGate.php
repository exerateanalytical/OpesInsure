<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Models\DocumentIssuanceProfile;
use App\Models\InsuranceProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Product document acceptance gate (16 checks). Adaptive model: it BLOCKS
 * product publication only when the product's issuance is configured as
 * OPES_GENERATED; MANUAL_UPLOAD / INSURER_API / HYBRID (carrier-document
 * mode) insurers pass, and failures are returned as warnings.
 */
final class ProductDocumentGate
{
    public const CHECKS = [
        'PRE_CONTRACT_MAPPED', 'POLICY_MAPPED', 'SCHEDULE_MAPPED', 'GENERAL_CONDITIONS_MAPPED', 'CERTIFICATES_MAPPED',
        'ENDORSEMENT_MAPPED', 'RENEWAL_MAPPED', 'CLAIM_MAPPED', 'FINANCE_MAPPED', 'LANGUAGES_EN_FR', 'ISSUER_CONFIGURED',
        'NUMBERING_CONFIGURED', 'SIGNATURE_CONFIGURED', 'QR_CONFIGURED', 'APPROVED_TEMPLATES', 'SAMPLE_PACK_RENDERED',
    ];

    public function __construct(private DocumentPackResolver $packs, private DocumentRegister $register, private DocumentTemplateService $templates, private DocumentNumberAllocator $numbers) {}

    /** @return array{mode: string, blocking: bool, passed: bool, checks: array<int, array{code: string, passed: bool, detail: string}>} */
    public function evaluate(InsuranceProduct $product): array
    {
        $profile = DocumentIssuanceProfile::where('carrier_id', $product->carrier_id)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))->orderByRaw('product_id IS NULL')->first();
        $mode = $profile?->issuance_mode ?? 'MANUAL_UPLOAD';
        $generated = $mode === 'OPES_GENERATED' || $mode === 'HYBRID';

        $nb = $this->packs->resolveForProduct($product, 'POLICY_ISSUED');
        $codes = array_column($nb['items'], 'document_type_code');
        $has = fn (array $any, array $list) => (bool) array_intersect($any, $list);
        $groupOf = fn (string $c) => $this->register->describe($c);
        $class = $nb['insurance_class'];
        $ctx = ['carrier_id' => $product->carrier_id, 'tenant_id' => null, 'broker' => false, 'product_id' => $product->id, 'insurance_class' => in_array($class, ['LIFE', 'GROUP_LIFE'], true) ? 'LIFE' : $class];

        $toGenerate = array_values(array_unique(array_column(array_filter($nb['items'], fn ($i) => $i['mode'] === 'GENERATE' && $i['required_level'] === 'REQUIRED'), 'document_type_code')));
        $templates = [];
        foreach ($toGenerate as $c) {
            $templates[$c] = $this->templates->candidates($c, $ctx);
        }
        $missingTemplates = array_keys(array_filter($templates, fn ($t) => $t->isEmpty()));
        $langOk = array_filter($templates, function ($t) {
            $langs = $t->pluck('language')->all();

            return in_array('BILINGUAL', $langs, true) || (in_array('FR', $langs, true) && in_array('EN', $langs, true));
        });
        $carrierMode = ! $generated;

        $checks = [
            ['PRE_CONTRACT_MAPPED', $has(array_filter($codes, fn ($c) => $groupOf($c)['group_code'] === 'PRE_CONTRACT' || $groupOf($c)['input_document']), $codes), 'Quote / proposal / declarations in the new-business pack'],
            ['POLICY_MAPPED', $has(['INSURANCE_POLICY', 'MASTER_GROUP_POLICY', 'MARINE_CARGO_POLICY'], $codes), 'Policy document'],
            ['SCHEDULE_MAPPED', $has(['POLICY_SCHEDULE', 'LIFE_POLICY_SCHEDULE', 'CORPORATE_BENEFIT_SCHEDULE', 'FLEET_VEHICLE_SCHEDULE'], $codes), 'Schedule (conditions particulières)'],
            ['GENERAL_CONDITIONS_MAPPED', in_array('GENERAL_CONDITIONS', $codes, true) || in_array($class, ['HEALTH_INDIVIDUAL', 'CORPORATE_HEALTH'], true), 'General conditions'],
            ['CERTIFICATES_MAPPED', in_array($class, ['LIFE'], true) || (bool) array_filter($codes, fn ($c) => $groupOf($c)['display_group'] === 'CERTIFICATES'), 'Proof-of-cover certificates'],
            ['ENDORSEMENT_MAPPED', in_array('POLICY_ENDORSEMENT', array_column($this->packs->resolveForProduct($product, 'ENDORSEMENT_ISSUED')['items'], 'document_type_code'), true), 'Endorsement pack'],
            ['RENEWAL_MAPPED', in_array('RENEWAL_CONFIRMATION', array_column($this->packs->resolveForProduct($product, 'RENEWAL_ISSUED')['items'], 'document_type_code'), true), 'Renewal pack'],
            ['CLAIM_MAPPED', in_array('CLAIM_ACKNOWLEDGEMENT', array_column($this->packs->resolveForProduct($product, 'CLAIM_REGISTERED')['items'], 'document_type_code'), true), 'Claim pack'],
            ['FINANCE_MAPPED', $has(['PREMIUM_RECEIPT', 'GROUP_PREMIUM_STATEMENT'], $codes), 'Premium receipt / statement'],
            ['LANGUAGES_EN_FR', $carrierMode || count($langOk) === count($templates), $carrierMode ? 'Carrier-document mode' : 'FR + EN (or bilingual) templates for every generated document'],
            ['ISSUER_CONFIGURED', $profile !== null || $carrierMode, $profile ? 'Issuance profile '.$mode.($generated && ! $profile->opes_rendering_authorized ? ' — insurer has NOT authorized OpesInsure rendering' : '') : 'No profile: carrier uploads its own documents'],
            ['NUMBERING_CONFIGURED', (bool) array_filter($codes, fn ($c) => $this->numbers->config(null, $this->numbers->familyFor(null, $c))['prefix'] !== ''), 'Numbering families resolve'],
            ['SIGNATURE_CONFIGURED', $carrierMode || ($profile && ($profile->signature_mode === 'NONE' || filled($profile->signatory_name))), 'Signature mode + signatory'],
            ['QR_CONFIGURED', $carrierMode || ($profile && $profile->qr_enabled), 'QR verification on verifiable documents'],
            ['APPROVED_TEMPLATES', $carrierMode || $missingTemplates === [], $missingTemplates ? 'Missing published templates: '.implode(', ', $missingTemplates) : 'Published template for every generated document'],
            ['SAMPLE_PACK_RENDERED', $carrierMode || ($missingTemplates === [] && $this->sampleRenders($templates)), 'Sample pack renders'],
        ];
        if ($generated && $profile && ! $profile->opes_rendering_authorized) {
            $checks[10][1] = false;
        }

        $rows = array_map(fn ($c) => ['code' => $c[0], 'passed' => (bool) $c[1], 'detail' => $c[2]], $checks);
        $passed = ! in_array(false, array_column($rows, 'passed'), true);

        return ['mode' => $mode, 'blocking' => $mode === 'OPES_GENERATED', 'passed' => $passed, 'checks' => $rows];
    }

    /** Called by CatalogueService::publish: throws only for OPES_GENERATED products. @return array<int, string> warnings */
    public function assertPublishable(InsuranceProduct $product): array
    {
        $r = $this->evaluate($product);
        $failed = array_values(array_filter($r['checks'], fn ($c) => ! $c['passed']));
        if ($failed && $r['blocking']) {
            throw ValidationException::withMessages(['documents' => 'Document acceptance gate failed: '.implode('; ', array_map(fn ($c) => $c['code'].' ('.$c['detail'].')', $failed))]);
        }

        return array_map(fn ($c) => $c['code'], $failed);
    }

    private function sampleRenders(array $templates): bool
    {
        try {
            $first = collect($templates)->flatten()->first();
            if (! $first) {
                return false;
            }
            Pdf::loadHTML('<h1>'.e($first->title_en).'</h1>')->output();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
