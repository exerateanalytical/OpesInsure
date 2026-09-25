<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\DocumentCatalogue\CanonicalFieldDictionary;
use App\Application\Documents\DemoDocumentMark;
use App\Application\Documents\Security\DocumentVerificationPresenter;
use App\Application\Documents\Security\SecurityArtwork;
use App\Application\Documents\Security\VerificationCredentials;

/**
 * View model of the zoned A4 master layout (resources/views/pdf/engine-shell.blade.php):
 * document_system.a4_zones A (header security band) .. G (compliance footer) filled from the frozen
 * canonical values, and the 5 master shells (TPL-SHELL-QUOTE / POLICY-SCHEDULE / POLICY-CERTIFICATE /
 * MOTOR-ATTESTATION / PREMIUM-RECEIPT) selecting the Zone C/D composition. Every other document uses the
 * generic zoned shell. Values without a canonical source are printed as a marked PENDING_VERIFICATION
 * placeholder (document_implementation_policy §1.1), never invented.
 */
final class DocumentShellView
{
    public const SHELLS = [
        'TPL-SHELL-QUOTE-001' => 'QUOTE', 'TPL-SHELL-POLICY-SCHEDULE-001' => 'SCHEDULE', 'TPL-SHELL-POLICY-CERTIFICATE-001' => 'CERTIFICATE',
        'TPL-SHELL-MOTOR-ATTESTATION-001' => 'MOTOR', 'TPL-SHELL-PREMIUM-RECEIPT-001' => 'RECEIPT',
    ];

    public const PENDING = 'PENDING_VERIFICATION';

    /** @param array<string, mixed> $in */
    public static function data(array $in): array
    {
        $lang = $in['lang'];
        $L = fn (string $fr, string $en) => match ($lang) { 'EN' => $en, 'FR' => $fr, default => $fr.' / '.$en };
        $v = $in['values'];
        $security = $in['security'];
        $controls = $security['controls'];
        $type = $in['type'];
        $policy = $in['policy'];
        $code = (string) $type['code'];
        $shellCode = $security['master_shell_code'] ?: 'TPL-SHELL-GENERIC';
        $shell = self::SHELLS[$shellCode] ?? 'GENERIC';
        $family = SecurityArtwork::familyOf($code);
        $color = SecurityArtwork::FAMILY_COLORS[$family] ?? SecurityArtwork::FAMILY_COLORS['DEFAULT'];
        $seed = $family.'|'.$in['number'];
        $money = fn ($minor, $cur = null) => $minor === null ? null : number_format(((int) $minor) / 100, 0, '.', ' ').' '.($cur ?? $policy->currency);
        $date = fn (?string $iso, bool $time = false) => $iso ? \Carbon\Carbon::parse($iso)->setTimezone(config('app.timezone'))->format($time ? 'd/m/Y H:i' : 'd/m/Y') : null;
        $label = fn (string $key) => $L(CanonicalFieldDictionary::KEYS[$key][1] ?? $key, CanonicalFieldDictionary::KEYS[$key][0] ?? $key);
        $row = fn (string $key, $value, bool $strong = false) => ['label' => $label($key), 'value' => $value, 'strong' => $strong];
        $claim = $in['claim'];
        $tx = $in['transaction'];
        $facts = (array) $in['subjectFacts'];

        // Zone B — identity.
        $identity = array_values(array_filter([
            $row('document.number', $in['number'], true),
            $policy->policy_number ? $row('policy.number', $policy->policy_number.' (v'.(int) $policy->version.')', true) : null,
            $claim ? $row('claim.number', $claim->claim_number) : null,
            $tx ? $row('endorsement.number', $tx->transaction_number) : null,
            $row('document.issued_at', $in['issuedAt']->format('d/m/Y H:i').' ('.config('app.timezone').')'),
            $row('policy.effective_from', $date($v['policy.effective_from'] ?? null)),
            $row('policy.effective_until', $date($v['policy.effective_until'] ?? null)),
            ['label' => $L('Agence', 'Branch'), 'value' => self::PENDING, 'strong' => false],
            $row('document.template_version', $in['template']->code.' v'.$in['template']->version),
            $row('document.status', $in['status']),
        ]));

        // Zone C — party / risk (vehicle identity is the strongest field on the motor attestation).
        $party = array_values(array_filter([
            $row('party.name', $v['party.name'] ?? null, true),
            $row('policy.insurer', $v['policy.insurer'] ?? null),
            $in['intermediary'] ? ['label' => $L('Intermédiaire', 'Intermediary'), 'value' => $in['intermediary']['name'].($in['intermediary']['licence'] ? ' ('.$in['intermediary']['licence'].')' : ''), 'strong' => false] : null,
            ($v['policy.product'] ?? null) ? $row('policy.product', $v['policy.product'].(($v['policy.insurance_class'] ?? null) ? ' — '.$v['policy.insurance_class'] : '')) : null,
            ($in['subject']['label'] ?? null) ? $row('risk.summary', $in['subject']['label'], true) : (($v['risk.summary'] ?? null) ? $row('risk.summary', $v['risk.summary']) : null),
        ]));
        $vehicle = [];
        if (($in['subject']['type'] ?? null) === 'VEHICLE' || $shell === 'MOTOR' || ($v['risk.registration_number'] ?? null)) {
            foreach (['risk.registration_number', 'risk.vin', 'risk.make', 'risk.model', 'risk.usage'] as $k) {
                if (($v[$k] ?? null) !== null) {
                    $vehicle[] = $row($k, $v[$k], $k === 'risk.registration_number');
                }
            }
            if (($v['risk.model_year'] ?? null) !== null) {
                $vehicle[] = ['label' => $L('Année modèle', 'Model year'), 'value' => $v['risk.model_year'], 'strong' => false];
            }
        }

        // Zone D — transaction content.
        $premium = [];
        if ($shell !== 'MOTOR' && $shell !== 'CERTIFICATE' && ($v['premium.gross'] ?? null) !== null && ! in_array($type['display_group'], ['CLAIMS'], true)) {
            $offer = $policy->proposal?->offer;
            $premium = array_values(array_filter([
                $offer?->premium_minor !== null ? ['label' => $L('Prime nette', 'Net premium'), 'value' => $money($offer->premium_minor)] : null,
                ($v['premium.taxes'] ?? null) !== null ? $row('premium.taxes', $money($v['premium.taxes'])) : ['label' => $label('premium.taxes'), 'value' => self::PENDING],
                $row('premium.gross', $money($v['premium.gross']), true),
            ]));
        }
        $payment = [];
        if ($shell === 'RECEIPT' || ($v['payment.reference'] ?? null)) {
            $payment = array_values(array_filter([
                $row('payment.reference', $v['payment.reference'] ?? null),
                ($v['payment.amount'] ?? null) !== null ? $row('payment.amount', $money($v['payment.amount']), true) : null,
                $row('payment.paid_at', $date($v['payment.paid_at'] ?? null, true)),
                $row('payment.method', $v['payment.method'] ?? null),
                $row('payment.status', $v['payment.status'] ?? null, true),
            ], fn ($r) => $r !== null && $r['value'] !== null));
        }
        $changes = [];
        foreach ((array) ($v['endorsement.changes'] ?? []) as $k => $val) {
            $changes[] = ['label' => ucwords(str_replace('_', ' ', (string) $k)), 'value' => is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE)];
        }
        $notices = match ($shell) {
            'QUOTE' => [$L('Ce devis ne constitue pas une preuve d\'assurance ; il est soumis à la souscription et à sa durée de validité.', 'This quotation is not proof of cover; it is subject to underwriting and its validity period.')],
            'MOTOR' => [$L('Une photocopie ou capture ne prouve pas la validité actuelle : seul le statut de vérification en ligne fait foi.', 'A photocopy or screenshot does not independently prove current validity; the online verification status is authoritative.')],
            'RECEIPT' => [$L('« PAYÉ » n\'est affiché qu\'après rapprochement du paiement.', '"PAID" is shown only after the payment is reconciled.')],
            default => [$L('Document soumis aux conditions générales et particulières de la police.', 'Subject to the policy general and special conditions.')],
        };

        // Zone E — authorization / signature.
        $sig = $controls['signature'];
        $authorization = [
            'issuer' => $in['issuerName'],
            'role' => $in['issuerName'] === 'OpesInsure' ? $L('Plateforme émettrice', 'Issuing platform') : $L('Émetteur autorisé', 'Authorized issuer'),
            'authorization_reference' => $in['profile']?->authorization_reference ?? self::PENDING,
            'signatory' => $in['profile'] && $in['profile']->signature_mode !== 'NONE' && $in['profile']->signatory_name ? $in['profile']->signatory_name.' — '.$in['profile']->signatory_title : null,
            'digital_signature' => $sig['status'] === 'APPLIED' ? 'ED25519 ('.config('document_security.signing.key_id').')' : ($sig['status'] === 'CONFIG_REQUIRED' ? 'CONFIG_REQUIRED' : null),
            'maker_checker' => $controls['maker_checker']['status'] === 'NOT_APPLICABLE' ? null : ($controls['maker_checker']['evidence'] ?? 'CONFIG_REQUIRED'),
            'timestamp' => $in['issuedAt']->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        // Watermark (security matrix §5 profiles) and status overlays (WM-STATUS).
        $class = $security['confidentiality_class'];
        $watermark = null;
        if ($controls['watermark']['status'] === 'APPLIED') {
            $fragment = match (true) {
                $class === 'PUBLIC_VERIFY' => substr($in['number'], -6),
                $class === 'MEDICAL_RESTRICTED' => 'MEDICAL · '.DocumentVerificationPresenter::mask((string) ($in['subject']['key'] ?? $policy->policy_number), 4),
                $class === 'FINANCIAL_RESTRICTED' => 'FINANCE · '.DocumentVerificationPresenter::mask((string) ($v['payment.reference'] ?? $policy->policy_number), 4),
                $family === 'CLAIMS' => 'CLAIMS · '.DocumentVerificationPresenter::mask((string) ($claim?->claim_number ?? $policy->policy_number), 4),
                default => DocumentVerificationPresenter::mask((string) $policy->policy_number, 4),
            };
            $watermark = ['text' => mb_strtoupper($in['issuerName']).' · '.$fragment, 'opacity' => ($controls['watermark']['variant'] ?? null) === 'LIGHT' ? 0.05 : 0.09];
        }
        $overlay = null;
        $wv = (string) ($controls['watermark']['variant'] ?? '');
        if (str_starts_with($wv, 'STATUS:')) {
            $overlay = substr($wv, 7);
        }
        $env = app()->environment();
        $envMark = DemoDocumentMark::active() ? null : match (true) {
            in_array($env, ['production'], true) => null,
            in_array($env, ['local', 'development'], true) => 'DEVELOPMENT — NOT VALID',
            $env === 'sandbox' => 'SANDBOX',
            default => 'UAT — NOT VALID',
        };

        return [
            'lang' => $lang, 'shell' => $shell, 'shellCode' => $shellCode, 'L' => $L,
            'titleFr' => $in['template']->title_fr, 'titleEn' => $in['template']->title_en, 'documentNumber' => $in['number'],
            'issuerName' => $in['issuerName'], 'family' => $family, 'familyColor' => $color, 'letterhead' => $in['letterhead'] ?? null,
            'tier' => $security['tier'], 'assurance' => $security['assurance'], 'confidentiality' => $class, 'specId' => $security['canonical_spec_id'],
            'guillocheHeader' => $controls['guilloche']['status'] === 'APPLIED' ? SecurityArtwork::guilloche($seed, 760, 46, $color, ($controls['guilloche']['variant'] ?? '') === 'LIGHT' ? 0.22 : 0.4, ($controls['guilloche']['variant'] ?? '') === 'LIGHT' ? 8 : 16) : null,
            'rosette' => $controls['guilloche']['status'] === 'APPLIED' && in_array($shell, ['CERTIFICATE', 'MOTOR', 'SCHEDULE'], true) ? SecurityArtwork::rosette($seed, 300, $color, 0.10) : null,
            'microtext' => $controls['microtext']['status'] === 'APPLIED' ? SecurityArtwork::microtext($in['issuerName'], $in['number']) : null,
            'antiCopy' => $controls['anti_copy']['status'] === 'APPLIED' ? SecurityArtwork::antiCopy(240, 96, $color) : null,
            'seal' => $controls['seal']['status'] === 'APPLIED' ? SecurityArtwork::seal($seed, 96, $color) : null,
            'watermark' => $watermark, 'statusOverlay' => $overlay, 'envMark' => $envMark,
            'identity' => $identity, 'party' => $party, 'vehicle' => $vehicle, 'sections' => $in['sections'], 'coverages' => $in['coverages'],
            'premium' => $premium, 'payment' => $payment, 'changes' => $changes, 'notices' => $notices, 'eventLabel' => $in['label'],
            'pending' => array_slice((array) ($in['requirements']['no_source'] ?? []), 0, 12),
            'authorization' => $authorization,
            'qr' => $in['qr'], 'verificationCode' => VerificationCredentials::display($in['verification']), 'verifyUrl' => $in['verifyUrl'],
            'hashFragment' => substr((string) $in['contentHash'], 0, 16), 'money' => $money,
            'footer' => [
                'registered_office' => self::PENDING, 'contact' => self::PENDING,
                'template' => $in['template']->code.' v'.$in['template']->version.' ('.$in['template']->ownership.')',
                'classification' => $class.' · '.implode('/', (array) $security['access_profiles']),
            ],
        ];
    }
}
