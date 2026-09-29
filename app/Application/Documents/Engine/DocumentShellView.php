<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\DocumentCatalogue\CanonicalFieldDictionary;
use App\Application\Documents\Security\DocumentVerificationPresenter;
use App\Application\Documents\Security\SecurityArtwork;
use App\Application\Documents\Security\VerificationCredentials;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

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
        $L = fn (string $fr, string $en) => match ($lang) {
            'EN' => $en, 'FR' => $fr, default => $fr.' / '.$en
        };
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
        // XAF prints as FCFA (one formatter with the mapped-field reader).
        $money = fn ($minor, $cur = null) => $minor === null ? null : \App\Application\Documents\Security\MappedFieldValues::money((int) $minor, (string) ($cur ?? $policy->currency ?? 'XAF'));
        $date = fn (?string $iso, bool $time = false) => $iso ? Carbon::parse($iso)->setTimezone(config('app.timezone'))->format($time ? 'd/m/Y H:i' : 'd/m/Y') : null;
        $label = fn (string $key) => $L(CanonicalFieldDictionary::KEYS[$key][1] ?? $key, CanonicalFieldDictionary::KEYS[$key][0] ?? $key);
        $row = fn (string $key, $value, bool $strong = false) => ['label' => $label($key), 'value' => self::humanCode($value, $L), 'strong' => $strong];
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
            // Short form in Zone B (canonical spec id + language + version); the full lineage code stays in Zone G.
            $row('document.template_version', str_contains((string) $in['template']->code, '|')
                ? trim(($security['canonical_spec_id'] ?? $code).' '.($in['template']->language ?? '')).' v'.$in['template']->version
                : $in['template']->code.' v'.$in['template']->version),
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

        // The vehicle rows already identify the risk: the flattened risk summary would repeat them.
        if ($vehicle !== [] && ! ($in['subject']['label'] ?? null)) {
            $riskLabel = $label('risk.summary');
            $party = array_values(array_filter($party, fn ($r) => $r['label'] !== $riskLabel));
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
        $wmProfile = $controls['watermark']['profile']['code'] ?? match ($class) {
            'PUBLIC_VERIFY' => 'WM-PUBLIC-PROOF', 'MEDICAL_RESTRICTED' => 'WM-MEDICAL', 'FINANCIAL_RESTRICTED' => 'WM-FINANCE', default => $family === 'CLAIMS' ? 'WM-CLAIMS' : 'WM-PRIVATE',
        };
        $watermark = null;
        if ($controls['watermark']['status'] === 'APPLIED') {
            // Each profile prints only its own fragment; WM-MEDICAL never carries diagnosis text.
            $fragment = match ($wmProfile) {
                'WM-PUBLIC-PROOF' => substr($in['number'], -6),
                'WM-MEDICAL' => 'MEDICAL · RESTRICTED · '.DocumentVerificationPresenter::mask((string) ($in['subject']['key'] ?? $policy->policy_number), 4),
                'WM-FINANCE' => 'FINANCE · '.DocumentVerificationPresenter::mask((string) ($v['payment.reference'] ?? $policy->policy_number), 4),
                'WM-CLAIMS' => 'CLAIMS · '.DocumentVerificationPresenter::mask((string) ($claim?->claim_number ?? $policy->policy_number), 4),
                default => DocumentVerificationPresenter::mask((string) $policy->policy_number, 4),
            };
            $watermark = ['text' => mb_strtoupper($in['issuerName']).' · '.$fragment, 'opacity' => ($controls['watermark']['variant'] ?? null) === 'LIGHT' ? 0.05 : 0.09,
                'profile' => $wmProfile, 'rosette' => $wmProfile === 'WM-PUBLIC-PROOF'];
        }
        // Seals (§6): only those whose backend authority exists at issuance; SEAL-01 needs verified corporate artwork.
        $sealNames = ['SEAL-01' => ["Sceau de l'émetteur", 'Corporate'], 'SEAL-02' => ['Authentification', 'Authentication'], 'SEAL-03' => ['Finance', 'Finance'],
            'SEAL-04' => ['Sinistres', 'Claims'], 'SEAL-05' => ['Prestataire', 'Provider'], 'SEAL-06' => ['Vérifié courtier', 'Broker verified'], 'SEAL-07' => ['Duplicata', 'Duplicate']];
        $seals = [];
        $sealPending = [];
        foreach ((array) ($controls['seal']['profiles'] ?? []) as $code => $st) {
            if (($st['status'] ?? null) === 'APPLIED' && isset($sealNames[$code])) {
                $seals[] = ['code' => $code, 'label' => $L(...$sealNames[$code])];
            } elseif (($st['status'] ?? null) === 'CONFIG_REQUIRED') {
                $sealPending[] = $code;
            }
        }
        if ($seals === [] && $controls['seal']['status'] === 'APPLIED' && ! isset($controls['seal']['profiles'])) {
            $seals[] = ['code' => 'SEAL-02', 'label' => $L(...$sealNames['SEAL-02'])];
        }
        // Physical profiles (§4): printed as a statement, never simulated (§10: no decorative hologram / UV).
        $physical = [];
        foreach ((array) ($controls['uv']['profiles'] ?? []) as $ps => $st) {
            $physical[$st['status']][] = $ps;
        }
        // One compact line per status ("PS-01, PS-02: CONFIG_REQUIRED") so Zone E keeps its height.
        $physical = array_values(array_map(fn ($codes, $st) => implode(', ', $codes).': '.$st, $physical, array_keys($physical)));
        $overlay = $security['profiles']['status_overlay'] ?? null;
        $wv = (string) ($controls['watermark']['variant'] ?? '');
        if ($overlay === null && str_starts_with($wv, 'STATUS:')) {
            $overlay = substr($wv, 7);
        }
        $env = app()->environment();
        $envMark = ! empty($in['demo']) ? null : match (true) {
            in_array($env, ['production'], true) => null,
            in_array($env, ['local', 'development'], true) => 'DEVELOPMENT — NOT VALID',
            $env === 'sandbox' => 'SANDBOX',
            default => 'UAT — NOT VALID',
        };

        // D2 mapped field rules (MAPPED_PLATFORM_SOURCE): rendered in their zone only when the platform holds a value.
        $mappedRows = self::mappedRows((array) ($in['requirements']['mapped'] ?? []), $v, $label, $money);
        // Canonical template fields (TEMPLATE_CONTENT_CONTRACT §2): every spec field of the document, in its zone.
        $content = (array) ($in['templateContent'] ?? []);
        // A key the template declares is printed once, with the template's bilingual label (not the mapped-rule bullet).
        $tplKeys = array_column(array_filter((array) ($content['fields'] ?? []), 'is_array'), 'key');
        foreach (['C', 'D'] as $z) {
            $mappedRows[$z] = array_values(array_filter($mappedRows[$z], fn ($r) => ! in_array($r['key'] ?? null, $tplKeys, true)));
        }
        // Fixed zones print these keys; a template field is skipped only when its key was actually printed there.
        $notRecorded = $L('Non renseigné', 'Not recorded');
        $fill = fn (array $rows) => array_map(fn ($r) => ($r['value'] ?? null) === null || $r['value'] === '' ? ['value' => $notRecorded] + $r : $r, $rows);
        $identity = $fill($identity);
        $party = $fill($party);
        $printed = ['policy.effective_from', 'policy.effective_until', 'party.name', 'policy.insurer'];
        foreach ([[$policy->policy_number, 'policy.number'], [$claim, 'claim.number'], [$tx, 'endorsement.number'], [$premium, 'premium.gross'], [$premium, 'premium.taxes'],
            [$in['coverages'], 'coverage.lines'], [$changes, 'endorsement.changes']] as [$shown, $key]) {
            if (! empty($shown)) {
                $printed[] = $key;
            }
        }
        $partyLabels = array_column($party, 'label');
        foreach (['policy.product', 'risk.summary'] as $key) {
            if (in_array($label($key), $partyLabels, true)) {
                $printed[] = $key;
            }
        }
        if (in_array($label('policy.product'), $partyLabels, true) && ($v['policy.insurance_class'] ?? null)) {
            $printed[] = 'policy.insurance_class';
        }
        foreach (['risk.registration_number', 'risk.vin', 'risk.make', 'risk.model', 'risk.usage'] as $key) {
            if (in_array($label($key), array_column($vehicle, 'label'), true)) {
                $printed[] = $key;
            }
        }
        if ($vehicle !== [] && ($v['risk.model_year'] ?? null) !== null) {
            $printed[] = 'risk.model_year';
        }
        if ($vehicle !== []) {
            $printed[] = 'risk.summary'; // the vehicle rows are the insured risk
        }
        foreach (['payment.reference', 'payment.amount', 'payment.paid_at', 'payment.method', 'payment.status'] as $key) {
            if (in_array($label($key), array_column($payment, 'label'), true)) {
                $printed[] = $key;
            }
        }
        $tplRows = self::templateFieldRows((array) ($content['fields'] ?? []), $v, $L, $money,
            array_merge(array_column($mappedRows['C'], 'label'), array_column($mappedRows['D'], 'label')), $printed);
        foreach ((array) ($content['notices'] ?? []) as $n) {
            $text = match ($lang) {
                'EN' => $n['en'] ?? '', 'FR' => $n['fr'] ?? '', default => trim(($n['fr'] ?? '').' / '.($n['en'] ?? ''), ' /')
            };
            if ($text !== '') {
                $notices[] = $text;
            }
        }
        $demoRecord = (bool) ($in['demo'] ?? false);

        return [
            'templateParty' => $tplRows['C'], 'templateContent' => $tplRows['D'], 'templateTables' => $tplRows['T'], 'printedKeys' => array_values(array_unique($printed)), 'demoRecord' => $demoRecord,
            'mappedParty' => $mappedRows['C'], 'mappedContent' => $mappedRows['D'],
            'lang' => $lang, 'shell' => $shell, 'shellCode' => $shellCode, 'L' => $L,
            'titleFr' => $in['template']->title_fr, 'titleEn' => $in['template']->title_en, 'documentNumber' => $in['number'],
            'issuerName' => $in['issuerName'], 'family' => $family, 'familyColor' => $color, 'letterhead' => $in['letterhead'] ?? null,
            'tier' => $security['tier'], 'assurance' => $security['assurance'], 'confidentiality' => $class, 'specId' => $security['canonical_spec_id'],
            'guillocheHeader' => $controls['guilloche']['status'] === 'APPLIED' ? SecurityArtwork::guilloche($seed, 760, 46, $color, ($controls['guilloche']['variant'] ?? '') === 'LIGHT' ? 0.22 : 0.4, ($controls['guilloche']['variant'] ?? '') === 'LIGHT' ? 8 : 16) : null,
            'rosette' => $controls['guilloche']['status'] === 'APPLIED' && (in_array($shell, ['CERTIFICATE', 'MOTOR', 'SCHEDULE'], true) || ($watermark['rosette'] ?? false)) ? SecurityArtwork::rosette($seed, 300, $color, 0.10) : null,
            'microtext' => $controls['microtext']['status'] === 'APPLIED' ? SecurityArtwork::microtext($in['issuerName'], $in['number']) : null,
            'antiCopy' => $controls['anti_copy']['status'] === 'APPLIED' ? SecurityArtwork::antiCopy(240, 96, $color) : null,
            'seal' => $controls['seal']['status'] === 'APPLIED' && $seals !== [] ? SecurityArtwork::seal($seed, 96, $color) : null,
            'seals' => $seals, 'sealPending' => $sealPending, 'physicalProfiles' => $physical,
            'watermark' => $watermark, 'statusOverlay' => $overlay, 'envMark' => $envMark,
            'identity' => $identity, 'party' => $party, 'vehicle' => $vehicle, 'sections' => $in['sections'], 'coverages' => $in['coverages'],
            'premium' => $premium, 'payment' => $payment, 'changes' => $changes, 'notices' => $notices, 'eventLabel' => $in['label'],
            'pending' => array_slice((array) ($in['requirements']['no_source'] ?? []), 0, 12),
            'authorization' => $authorization,
            'qr' => $in['qr'], 'verificationCode' => VerificationCredentials::display($in['verification']), 'verifyUrl' => $in['verifyUrl'],
            'hashFragment' => substr((string) $in['contentHash'], 0, 16), 'money' => $money,
            'footer' => [
                'registered_office' => self::PENDING, 'contact' => self::PENDING,
                // Readable reference (the lineage key "CODE|PLATFORM|-|-|-|-|BILINGUAL" stays in the registry).
                'template' => trim(($security['canonical_spec_id'] ?? '').' '.strtok((string) $in['template']->code, '|')).' v'.$in['template']->version,
                'classification' => $class.' · '.implode('/', (array) $security['access_profiles']).($watermark ? ' · '.$watermark['profile'] : ''),
            ],
        ];
    }

    /** Keys already printed by a fixed zone row, or document-level keys owned by Zones A/B/F. */
    private const SHOWN = ['party.name', 'policy.insurer', 'policy.product', 'policy.insurance_class', 'policy.number', 'policy.effective_from', 'policy.effective_until',
        'risk.summary', 'risk.registration_number', 'risk.vin', 'risk.make', 'risk.model', 'risk.usage', 'risk.model_year', 'premium.gross', 'premium.taxes',
        'payment.reference', 'payment.amount', 'payment.paid_at', 'payment.method', 'payment.status', 'coverage.lines', 'endorsement.changes', 'claim.number', 'endorsement.number'];

    /** Zone C = parties / insured risk; Zone D = transaction content. */
    private const PARTY_PREFIXES = ['party.', 'insured.', 'beneficiary.', 'risk.', 'consent.', 'intermediary.'];

    /**
     * @param  array<string, array{key: string|null, source: string|null}>  $mapped  DocumentFieldRequirements::requiredKeys()['mapped']
     * @return array{C: array<int, array{label: string, value: string, strong: bool}>, D: array<int, array{label: string, value: string, strong: bool}>}
     */
    public static function mappedRows(array $mapped, array $values, callable $label, callable $money): array
    {
        $out = ['C' => [], 'D' => []];
        $seen = [];
        foreach ($mapped as $bullet => $m) {
            $key = $m['key'] ?? null;
            if (! is_string($key) || isset($seen[$key]) || in_array($key, self::SHOWN, true) || preg_match('/^(document|verification|template|confidentiality|issuer)\./', $key)) {
                continue;
            }
            $seen[$key] = true;
            $value = self::cell($values[$key] ?? null, $key, $money, null);
            if ($value === null) {
                continue; // absent: never a blank placeholder
            }
            $zone = 'D';
            foreach (self::PARTY_PREFIXES as $pfx) {
                if (str_starts_with($key, $pfx)) {
                    $zone = 'C';
                }
            }
            $out[$zone][] = ['label' => isset(CanonicalFieldDictionary::KEYS[$key]) ? $label($key) : ucfirst((string) $bullet), 'value' => $value, 'strong' => false, 'key' => $key];
        }

        return $out;
    }

    /**
     * Render the shell to PDF bytes (dompdf). "Page X / Y" is drawn on the canvas after layout: dompdf's CSS
     * counter(pages) is not resolved in generated content (it printed "1 / 0"), the canvas page_text is.
     *
     * @param  array<string, mixed>  $data  self::data()
     */
    public static function pdf(array $data, string $paper = 'a4'): string
    {
        $pdf = Pdf::loadView('pdf.engine-shell', $data)->setPaper($paper);
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $text = 'Page {PAGE_NUM} / {PAGE_COUNT}'; // same word in French and English
        $canvas->page_text($canvas->get_width() - 40 - 70, $canvas->get_height() - 30, $text, $font, 7.5, [0.2, 0.25, 0.32]);

        return $dompdf->output();
    }

    /**
     * @param  array<int, array{key?: string, label_en?: string, label_fr?: string, zone?: string, format?: string}>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $alreadyLabelled  labels printed by the mapped-field rows
     * @param  ?array<int, string>  $printed  keys the fixed zones actually printed (null: the static SHOWN list)
     * @return array{C: list<array<string, mixed>>, D: list<array<string, mixed>>, T: list<array{label: string, key: string, zone: string, columns: list<string>, rows: list<list<string>>, recorded: bool, value: string}>}
     */
    public static function templateFieldRows(array $fields, array $values, callable $L, callable $money, array $alreadyLabelled = [], ?array $printed = null): array
    {
        $out = ['C' => [], 'D' => [], 'T' => []];
        $seen = [];
        $notRecorded = $L('Non renseigné', 'Not recorded');
        foreach ($fields as $f) {
            $key = (string) ($f['key'] ?? '');
            $labelText = $L((string) ($f['label_fr'] ?? $f['label_en'] ?? $key), (string) ($f['label_en'] ?? $key));
            if ($key === '' || in_array($key, $printed ?? self::SHOWN, true) || isset($seen[$key.'|'.$labelText]) || in_array($labelText, $alreadyLabelled, true)
                || preg_match('/^(document|verification|template|confidentiality)\./', $key)) {
                continue;
            }
            $seen[$key.'|'.$labelText] = true;
            $zone = ($f['zone'] ?? 'D') === 'C' ? 'C' : 'D';
            if (($f['format'] ?? null) === 'table') {
                // Multi-row value (dependants, beneficiaries, census, movements ...): list of rows, one column per declared column key.
                $columns = array_values(array_filter((array) ($f['columns'] ?? []), 'is_array'));
                $rows = [];
                foreach (array_values(array_filter((array) ($values[$key] ?? []), 'is_array')) as $item) {
                    $cells = [];
                    foreach ($columns as $c) {
                        $ck = (string) ($c['key'] ?? $c[0] ?? '');
                        $cells[] = self::cell($item[$ck] ?? null, $ck, $money, $c['format'] ?? $c[3] ?? null) ?? '—';
                    }
                    $rows[] = $cells;
                }
                $out['T'][] = ['label' => $labelText, 'key' => $key, 'zone' => $zone, 'recorded' => $rows !== [], 'value' => $rows === [] ? $notRecorded : '',
                    'columns' => array_map(fn ($c) => $L((string) ($c['label_fr'] ?? $c[2] ?? $c['label_en'] ?? $c[1] ?? ''), (string) ($c['label_en'] ?? $c[1] ?? '')), $columns), 'rows' => $rows];

                continue;
            }
            $value = self::cell($values[$key] ?? null, $key, $money, $f['format'] ?? null);
            $out[$zone][] = ['label' => $labelText, 'value' => $value === null ? $notRecorded : self::humanCode($value, $L), 'key' => $key, 'recorded' => $value !== null];
        }

        return $out;
    }

    /**
     * Stored codes ("PRIVATE", "ISSUED", "THIRD_PARTY_ONLY") read as words on the printed page, in the document's
     * language(s) through the shared FR dictionary (resources/lang/fr.json). Registration numbers, VINs, currencies
     * and markers such as PENDING_VERIFICATION are left as they are.
     */
    public static function humanCode(mixed $value, callable $L): mixed
    {
        if (! is_string($value) || ! preg_match('/^[A-Z]{4,}(_[A-Z]+)*$/', $value) || in_array($value, [self::PENDING, 'CONFIG_REQUIRED'], true)) {
            return $value;
        }
        $en = ucfirst(strtolower(str_replace('_', ' ', $value)));
        $fr = app('translator')->get($en, [], 'fr');

        return $L(is_string($fr) ? $fr : $en, $en);
    }

    /** One formatted value (format hint money | date | datetime | text, else inferred from the key / ISO date). */
    private static function cell(mixed $raw, string $key, callable $money, ?string $hint): ?string
    {
        $hint = in_array($hint, ['money', 'date', 'datetime', 'text'], true) ? $hint : null;
        if (is_string($raw) && ($hint === 'date' || $hint === 'datetime' || ($hint === null && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?/', $raw)))) {
            try {
                $raw = Carbon::parse($raw)->setTimezone(config('app.timezone'))->format($hint === 'datetime' ? 'd/m/Y H:i' : 'd/m/Y');
            } catch (\Throwable) {
                // not a date: printed as recorded
            }
        }

        return self::format($raw, $key, $money, $hint);
    }

    /**
     * Integer amounts in minor units are printed as money when the key names an amount (TEMPLATE_CONTENT_CONTRACT §2):
     * premium.*, *_minor, or a last segment naming an amount (gross, net, balance, retention, ceded premium, loss ...).
     */
    public const MONEY_KEY_PATTERN = '/(^premium\.|_minor$|amount|premium|gross|(^|[._])net($|_)|balance|retention|ceded|prior_payments|payments?$|loss|fees?$|taxes?$|total|value|limit|deductible|sum_insured|refund|excess|reserve|settlement\.|recovery|commission|levy|levies|price|cost|due$|outstanding)/';

    public static function isMoneyKey(string $key): bool
    {
        return ! preg_match('/(count|number|_no$|percent|pct|rate|ratio|year|days|months|share$)/', $key) && (bool) preg_match(self::MONEY_KEY_PATTERN, $key);
    }

    private static function format(mixed $v, string $key, callable $money, ?string $hint = null): ?string
    {
        if ($v === null || $v === '' || $v === [] || $v === false) {
            return null;
        }
        if (is_bool($v)) {
            return 'Yes / Oui';
        }
        if (is_int($v) && ($hint === 'money' || ($hint === null && self::isMoneyKey($key)))) {
            return $money($v);
        }
        if (is_scalar($v)) {
            $s = trim((string) $v);

            return $s === '' ? null : $s;
        }
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $k => $item) {
                $txt = is_array($item) ? ($item['name'] ?? $item['label'] ?? $item['code'] ?? null) : $item;
                if (is_array($txt)) {
                    $txt = $txt['en'] ?? reset($txt);
                }
                if ($txt === null || $txt === '' || ! is_scalar($txt)) {
                    continue;
                }
                $parts[] = is_string($k) ? ucwords(str_replace('_', ' ', $k)).': '.$txt : (string) $txt;
            }

            return $parts === [] ? null : implode(' · ', array_slice($parts, 0, 20));
        }

        return null;
    }
}
