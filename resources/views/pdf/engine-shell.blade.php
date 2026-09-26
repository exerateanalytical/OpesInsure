<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $documentNumber }}</title>@include('pdf._style')
{{-- Canonical A4 master layout (document_system.a4_zones A..G + master shells). View model: App\Application\Documents\Engine\DocumentShellView. --}}
<style>
  @page { margin: 118px 40px 78px 40px; }
  .zone-a { position: fixed; top: -104px; left: 0; right: 0; height: 96px; }
  .zone-g { position: fixed; bottom: -66px; left: 0; right: 0; height: 58px; border-top: 1px solid {{ $familyColor }}; font-size: 7.5px; color: #5b6b7d; }
  .zone-g .pageno:after { content: counter(page) " / " counter(pages); }
  .guil { position: absolute; top: 0; left: 0; width: 100%; height: 46px; }
  .micro { font-size: 3.2px; letter-spacing: 0.2px; color: {{ $familyColor }}; white-space: nowrap; overflow: hidden; line-height: 4px; }
  .wm { position: fixed; top: 40%; left: -10%; width: 120%; text-align: center; transform: rotate(-30deg); font-size: 30px; font-weight: bold; color: {{ $familyColor }}; z-index: -1; }
  .rosette { position: fixed; top: 28%; left: 28%; width: 300px; height: 300px; z-index: -2; }
  .overlay { position: fixed; top: 46%; left: 0; width: 100%; text-align: center; transform: rotate(-24deg); font-size: 64px; font-weight: bold; color: #c62828; opacity: 0.22; z-index: 900; }
  .envmark { border: 2px solid #c62828; color: #c62828; font-weight: bold; text-align: center; padding: 3px; font-size: 11px; margin-bottom: 6px; }
  .zone { margin-top: 8px; }
  .zone-title { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: {{ $familyColor }}; border-bottom: 1px solid {{ $familyColor }}; padding-bottom: 2px; margin-bottom: 2px; }
  table.grid { width: 100%; border-collapse: collapse; }
  table.grid td { padding: 3px 6px; border-bottom: 1px solid #e3e8ef; vertical-align: top; font-size: 9.5px; }
  table.grid td.k { width: 36%; color: #5b6b7d; }
  .hero { border: 1.5px solid {{ $familyColor }}; padding: 6px 10px; margin-top: 6px; }
  .hero .big { font-size: 18px; font-weight: bold; color: {{ $familyColor }}; }
  .pending { color: #9a6b00; font-style: italic; }
  .verify { border: 1px solid {{ $familyColor }}; padding: 6px; position: relative; }
  .anticopy { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
  .seal { width: 96px; height: 96px; }
  .tier { display: inline-block; border: 1px solid {{ $familyColor }}; color: {{ $familyColor }}; padding: 1px 6px; font-size: 8px; border-radius: 8px; }
  .statement { font-size: 12px; line-height: 1.5; text-align: center; margin: 10px 30px; }
</style>
</head>
<body>@include('pdf._demo_overlay')

{{-- Zone A — header security band (fixed: repeated on every page). --}}
<div class="zone-a">
  @if($guillocheHeader)<img class="guil" src="{{ $guillocheHeader }}" alt="">@endif
  <table style="width:100%;position:relative"><tr>
    <td style="width:62%">
      @include('pdf._letterhead', ['lhDefaultColor' => $familyColor, 'lhLogoHeight' => 30, 'lhHeaderHeight' => 38])
      <div style="font-weight:bold;font-size:12px;color:{{ $familyColor }}">
        @if($lang !== 'EN'){{ $titleFr }}@endif @if($lang === 'BILINGUAL') / @endif @if($lang !== 'FR'){{ $titleEn }}@endif
      </div>
    </td>
    <td style="text-align:right">
      <span class="status">{{ $documentNumber }}</span><br>
      <span class="tier">{{ $tier }} · {{ $assurance }}</span> <span class="tier">{{ $confidentiality }}</span>@if($specId) <span class="tier">{{ $specId }}</span>@endif
    </td>
  </tr></table>
  @if($microtext)<div class="micro">{{ $microtext }}</div>@endif
</div>

{{-- Zone G — compliance footer (fixed). --}}
<div class="zone-g">
  @if($microtext)<div class="micro">{{ $microtext }}</div>@endif
  <table style="width:100%"><tr>
    <td>@if(!empty($letterhead['footer_lines']))@include('pdf._letterhead_footer')@else{{ $L('Siège social', 'Registered office') }}: <span class="pending">{{ $footer['registered_office'] }}</span> · {{ $L('Contact', 'Contact') }}: <span class="pending">{{ $footer['contact'] }}</span><br>@endif
      {{ $L('Modèle', 'Template') }} {{ $footer['template'] }} · {{ $shellCode }} · {{ $footer['classification'] }}<br>
      {{ $L('Le statut du registre OpesInsure fait foi ; le QR n\'est qu\'un pointeur de vérification.', 'The OpesInsure registry status is authoritative; the QR is only a verification pointer.') }}</td>
    <td style="text-align:right;width:18%">{{ $L('Page', 'Page') }} <span class="pageno"></span></td>
  </tr></table>
</div>

@if($rosette)<img class="rosette" src="{{ $rosette }}" alt="">@endif
@if($watermark)<div class="wm" style="opacity: {{ $watermark['opacity'] }}">{{ $watermark['text'] }}</div>@endif
@if($statusOverlay)<div class="overlay">{{ $statusOverlay }}</div>@endif
@if($envMark)<div class="envmark">{{ $envMark }}</div>@endif

{{-- Zone B — document identity block. --}}
<div class="zone">
  <div class="zone-title">{{ $L('Identification du document', 'Document identity') }}</div>
  <table class="grid">
    @foreach(array_chunk($identity, 2) as $pair)
      <tr>@foreach($pair as $r)<td class="k">{{ $r['label'] }}</td><td>@if($r['value'] === 'PENDING_VERIFICATION')<span class="pending">{{ $r['value'] }}</span>@elseif(!empty($r['strong']))<strong>{{ $r['value'] }}</strong>@else{{ $r['value'] }}@endif</td>@endforeach</tr>
    @endforeach
  </table>
</div>

@if($shell === 'CERTIFICATE' || $shell === 'MOTOR')
  <div class="statement">
    {{ $L('Il est certifié que', 'This is to certify that') }} <strong>{{ collect($party)->firstWhere('strong', true)['value'] ?? '' }}</strong>
    {{ $L('est assuré(e) au titre de la police', 'is insured under policy') }} <strong>{{ collect($identity)->firstWhere('label', $L('N° de police', 'Policy number'))['value'] ?? '' }}</strong>
    {{ $L('pour la période indiquée, sous réserve des conditions de la police.', 'for the period stated, subject to the policy terms and conditions.') }}
  </div>
@endif

{{-- Zone C — party / risk summary. --}}
<div class="zone">
  <div class="zone-title">{{ $L('Parties et risque', 'Parties and risk') }}</div>
  <table class="grid">@foreach($party as $r)<tr><td class="k">{{ $r['label'] }}</td><td>@if(!empty($r['strong']))<strong>{{ $r['value'] }}</strong>@else{{ $r['value'] }}@endif</td></tr>@endforeach</table>
  @if(!empty($vehicle))
    <table class="grid" style="margin-top:4px">@foreach($vehicle as $r)<tr><td class="k">{{ $r['label'] }}</td><td>@if(!empty($r['strong']))<strong style="font-size:13px">{{ $r['value'] }}</strong>@else{{ $r['value'] }}@endif</td></tr>@endforeach</table>
  @endif
</div>

{{-- Zone D — main transaction content (composition by master shell). --}}
<div class="zone">
  <div class="zone-title">{{ $L('Contenu', 'Content') }} — {{ $eventLabel }}</div>
  @if($shell === 'RECEIPT' && !empty($payment))
    <div class="hero">@foreach($payment as $r)@if(!empty($r['strong']))<div class="big">{{ $r['value'] }}</div>@endif @endforeach</div>
  @endif
  @if(in_array($shell, ['QUOTE', 'SCHEDULE'], true) && !empty($premium))
    <div class="hero"><span class="muted">{{ $premium[count($premium) - 1]['label'] }}</span><div class="big">{{ $premium[count($premium) - 1]['value'] }}</div></div>
  @endif
  @foreach($sections as $section)
    @if(!empty($section['heading']))<h2>{{ $section['heading'] }}</h2>@endif
    @foreach($section['paragraphs'] as $p)<p>{{ $p }}</p>@endforeach
  @endforeach
  @if(!empty($coverages))
    <h2>{{ $L('Garanties', 'Cover') }}</h2>
    <table class="grid">
      <tr><td class="k"><strong>{{ $L('Garantie', 'Coverage') }}</strong></td><td><strong>{{ $L('Limite / capital', 'Limit / sum insured') }}</strong></td><td><strong>{{ $L('Franchise', 'Deductible') }}</strong></td></tr>
      @foreach($coverages as $c)
        <tr><td class="k">{{ is_array($c) ? ($c['name'] ?? $c['code'] ?? '') : $c }}</td>
          <td>{{ is_array($c) && isset($c['limit_minor']) ? $money($c['limit_minor']) : (is_array($c) && isset($c['sum_insured_minor']) ? $money($c['sum_insured_minor']) : '—') }}</td>
          <td>{{ is_array($c) && isset($c['deductible_minor']) ? $money($c['deductible_minor']) : '—' }}</td></tr>
      @endforeach
    </table>
  @endif
  @if(!empty($premium))
    <h2>{{ $L('Prime', 'Premium') }}</h2>
    <table class="grid">@foreach($premium as $r)<tr><td class="k">{{ $r['label'] }}</td><td>@if($r['value'] === 'PENDING_VERIFICATION')<span class="pending">{{ $r['value'] }}</span>@elseif(!empty($r['strong']))<strong>{{ $r['value'] }}</strong>@else{{ $r['value'] }}@endif</td></tr>@endforeach</table>
  @endif
  @if(!empty($payment))
    <h2>{{ $L('Paiement', 'Payment') }}</h2>
    <table class="grid">@foreach($payment as $r)<tr><td class="k">{{ $r['label'] }}</td><td>@if(!empty($r['strong']))<strong>{{ $r['value'] }}</strong>@else{{ $r['value'] }}@endif</td></tr>@endforeach</table>
  @endif
  @if(!empty($changes))
    <h2>{{ $L('Modifications', 'Changes') }}</h2>
    <table class="grid">@foreach($changes as $r)<tr><td class="k">{{ $r['label'] }}</td><td>{{ $r['value'] }}</td></tr>@endforeach</table>
  @endif
  @if(!empty($pending))
    <h2>{{ $L('Données à vérifier', 'Data pending verification') }}</h2>
    <p class="small pending">{{ implode(' · ', $pending) }} — {{ $L('aucune source canonique : non inventé', 'no canonical source: not invented') }} (PENDING_VERIFICATION)</p>
  @endif
  @foreach($notices as $n)<p class="small muted">{{ $n }}</p>@endforeach
</div>

<table style="width:100%;margin-top:10px;page-break-inside:avoid"><tr>
  {{-- Zone E — authorization / signature. --}}
  <td style="width:58%;vertical-align:top">
    <div class="zone-title">{{ $L('Autorisation / signature', 'Authorization / signature') }}</div>
    <table class="grid">
      <tr><td class="k">{{ $L('Émetteur', 'Issuer') }}</td><td><strong>{{ $authorization['issuer'] }}</strong> — {{ $authorization['role'] }}</td></tr>
      <tr><td class="k">{{ $L('Réf. d\'autorisation', 'Authorization ref.') }}</td><td>@if($authorization['authorization_reference'] === 'PENDING_VERIFICATION')<span class="pending">PENDING_VERIFICATION</span>@else{{ $authorization['authorization_reference'] }}@endif</td></tr>
      @if($authorization['signatory'])<tr><td class="k">{{ $L('Signataire', 'Signatory') }}</td><td>{{ $authorization['signatory'] }}</td></tr>@endif
      @if($authorization['digital_signature'])<tr><td class="k">{{ $L('Signature numérique', 'Digital signature') }}</td><td>@if($authorization['digital_signature'] === 'CONFIG_REQUIRED')<span class="pending">CONFIG_REQUIRED</span>@else{{ $authorization['digital_signature'] }}@endif</td></tr>@endif
      @if($authorization['maker_checker'])<tr><td class="k">{{ $L('Double contrôle', 'Maker-checker') }}</td><td>{{ $authorization['maker_checker'] }}</td></tr>@endif
      <tr><td class="k">{{ $L('Horodatage (UTC)', 'Timestamp (UTC)') }}</td><td>{{ $authorization['timestamp'] }}</td></tr>
    </table>
  </td>
  <td style="width:14%;text-align:center;vertical-align:middle">
    @if($seal)<img class="seal" src="{{ $seal }}" alt="{{ $seals[0]['code'] ?? 'SEAL-02' }}">@endif
    @foreach($seals as $s)<div class="small muted">{{ $s['code'] }} {{ $s['label'] }}</div>@endforeach
    @if(!empty($sealPending))<div class="small pending">{{ implode(', ', $sealPending) }}: CONFIG_REQUIRED</div>@endif
    @if(!empty($physicalProfiles))<div class="small pending">{{ $L('Impression sécurisée', 'Secure print') }}: {{ implode(' · ', $physicalProfiles) }} — {{ $L('original numérique', 'digital original') }}</div>@endif
  </td>
  {{-- Zone F — verification block. --}}
  <td style="width:28%;vertical-align:top">
    <div class="verify">
      @if($antiCopy)<img class="anticopy" src="{{ $antiCopy }}" alt="">@endif
      @if($qr)<div style="text-align:center"><img class="qr" style="width:92px;height:92px" src="{{ $qr }}" alt="QR"></div>@endif
      <div style="text-align:center;font-weight:bold">{{ $L('SCANNER POUR VÉRIFIER', 'SCAN TO VERIFY') }}</div>
      <div class="small">{{ $L('Code', 'Code') }}: <strong>{{ $verificationCode }}</strong></div>
      <div class="small">{{ $verifyUrl }}</div>
      <div class="small">SHA-256 #{{ $hashFragment }}</div>
      <div class="small muted">{{ $L('Validité déterminée par le statut en ligne (VALIDE / RÉVOQUÉ / REMPLACÉ).', 'Validity is determined by the live status (VALID / REVOKED / SUPERSEDED).') }}</div>
    </div>
  </td>
</tr></table>
</body></html>
