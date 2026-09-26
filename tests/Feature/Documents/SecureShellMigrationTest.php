<?php

declare(strict_types=1);

// D3 (DOCUMENT_SECURITY_COMPLETION_PLAN) — every PDF renders in the canonical secure shell.

use App\Application\Payments\MobilePaymentService;
use App\Application\Policies\PolicyDocumentService;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Quotes\QuoteDocumentRenderer;
use App\Models\Document;
use App\Models\PolicyIssuanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function d3PaidFixture(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(\App\Application\Payments\WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor, 'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');

    return $f;
}

function d3Approver(): \App\Models\User
{
    return \App\Models\User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->shellRenders = [];
    $this->otherPdfViews = [];
    View::composer('pdf.*', function ($view) {
        $name = $view->getName();
        if ($name === 'pdf.engine-shell') {
            $d = $view->getData();
            $this->shellRenders[] = ['number' => $d['documentNumber'], 'shell' => $d['shell'], 'code' => $d['verificationCode'], 'hash' => $d['hashFragment'],
                'micro' => $d['microtext'] !== null, 'guilloche' => $d['guillocheHeader'] !== null, 'qr' => $d['qr'] !== null];
        } elseif (! str_starts_with($name, 'pdf._')) {
            $this->otherPdfViews[] = $name;
        }
    });
});

it('D3 REQ-DOC-SEC-D3: payment -> issuance -> PDF download issues certificate, schedule and receipt in the secure shell', function () {
    $f = d3PaidFixture();

    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $policy = app(PolicyIssuanceService::class)->approve($request, ['policy_number' => 'POL-D3-'.Str::random(6), 'carrier_reference' => 'CR-D3'], d3Approver());
    $certificate = $policy->certificates()->firstOrFail();

    $docs = Document::where('policy_id', $policy->id)->whereIn('category', [PolicyDocumentService::CERTIFICATE, PolicyDocumentService::SCHEDULE])->get()->keyBy('category');
    expect($docs->keys()->sort()->values()->all())->toBe([PolicyDocumentService::CERTIFICATE, PolicyDocumentService::SCHEDULE]);

    // Both issuance PDFs came from the one shell, numbered by the certificate serial, with the security artwork.
    $shells = collect($this->shellRenders)->keyBy('shell');
    expect($shells->keys()->all())->toContain('CERTIFICATE', 'SCHEDULE')
        ->and($shells['CERTIFICATE']['number'])->toBe($certificate->serial_number)
        ->and($shells['CERTIFICATE']['qr'])->toBeTrue()
        ->and($this->otherPdfViews)->toBe([]);

    // Each document downloads through its signed link as a PDF.
    foreach ($docs as $doc) {
        $url = app(PolicyDocumentService::class)->downloadUrl($doc);
        $res = $this->get($url)->assertOk();
        expect($res->headers->get('Content-Type'))->toContain('application/pdf');
        expect(str_starts_with(Storage::disk('local')->get($doc->storage_key), '%PDF'))->toBeTrue();
    }

    // Issued documents stay immutable: ensure() again does not re-render or change the stored file.
    $before = $docs->map(fn ($d) => $d->sha256)->sortKeys()->all();
    $count = count($this->shellRenders);
    app(PolicyDocumentService::class)->ensure($policy->refresh());
    expect(count($this->shellRenders))->toBe($count)
        ->and(Document::whereIn('id', $docs->pluck('id'))->get()->keyBy('category')->map(fn ($d) => $d->sha256)->sortKeys()->all())->toBe($before);

    // Payment receipt: same receipt number, RECEIPT master shell, signed PDF download.
    $receiptUrl = URL::temporarySignedRoute('mobile.payments.receipt.pdf', now()->addMinutes(5), ['payment' => $f['payment']->id]);
    $res = $this->get($receiptUrl)->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/pdf')->and(str_starts_with($res->getContent(), '%PDF'))->toBeTrue();
    $last = end($this->shellRenders);
    expect($last['number'])->toBe(MobilePaymentService::receiptNumber($f['payment']->refresh()))->and($last['shell'])->toBe('RECEIPT');
});

it('D3 REQ-DOC-SEC-D3: the quotation PDF renders in the QUOTE shell with its quote number', function () {
    $f = d3PaidFixture();
    $bytes = app(QuoteDocumentRenderer::class)->pdf($f['quote']);
    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
    $last = end($this->shellRenders);
    expect($last['shell'])->toBe('QUOTE')->and($last['number'])->toBe((string) ($f['quote']->quote_number ?? $f['quote']->id))
        ->and($this->otherPdfViews)->toBe([]);
});

it('D3 REQ-DOC-SEC-D3: legacy PDF views are gone and no renderer references them', function () {
    foreach (['engine-document', 'payment-receipt', 'policy-certificate', 'policy-schedule', 'account-statement'] as $v) {
        expect(file_exists(resource_path("views/pdf/{$v}.blade.php")))->toBeFalse();
    }
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        // ProductDocumentGate only probes that the PDF engine can render (no document is produced).
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && $file->getFilename() !== 'ProductDocumentGate.php') {
            $src = file_get_contents($file->getPathname());
            if (preg_match("/loadView\\('pdf\\.(?!engine-shell)|loadHTML\\(/", $src)) {
                $offenders[] = $file->getPathname();
            }
        }
    }
    expect($offenders)->toBe([]);
});
