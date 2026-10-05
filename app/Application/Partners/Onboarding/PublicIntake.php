<?php

declare(strict_types=1);

namespace App\Application\Partners\Onboarding;

use App\Application\Documents\Scanning\DocumentScanQueue;
use App\Application\Notifications\Otp\OtpDeliveryService;
use App\Application\Notifications\Otp\SendOtpJob;
use App\Application\Uploads\FileSignature;
use App\Mail\NotificationMail;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared plumbing of the two anonymous partner intakes (partner self-service application, "claim this organisation"):
 * secret status links, one-time codes (SMS through the OTP service when a provider is configured, otherwise email),
 * uploads registered as documents of the platform tenant and held in the malware-scan queue until CLEAN, and the
 * EN/FR applicant emails. Codes and status tokens are only ever stored as hashes.
 */
final class PublicIntake
{
    public const CODE_TTL_MINUTES = 15;

    public const MAX_CODE_ATTEMPTS = 5;

    public const MAX_CODE_SENDS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const UPLOAD_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(private readonly OtpDeliveryService $otp, private readonly DocumentScanQueue $scans) {}

    /** The PLATFORM tenant that owns public intake documents (they belong to no customer tenant yet). */
    public function platformTenantId(): string
    {
        $id = DB::table('tenants')->where('type', 'PLATFORM')->orderBy('created_at')->value('id');
        abort_if($id === null, 503);

        return (string) $id;
    }

    /** @return array{0: string, 1: string} [token for the link, hash to store] */
    public static function newToken(): array
    {
        $token = Str::random(48);

        return [$token, self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function reference(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ym').'-'.strtoupper(Str::random(6));
    }

    public static function ipHash(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }

    /** "j•••@example.cm" / "+2376•••••12" — enough for the claimant to recognise the channel, never the full value. */
    public static function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$user, $domain] = explode('@', $destination, 2);

            return mb_substr($user, 0, 1).'•••@'.$domain;
        }

        return mb_substr($destination, 0, 5).str_repeat('•', max(0, mb_strlen($destination) - 7)).mb_substr($destination, -2);
    }

    /** Whether an SMS/WhatsApp code can actually be sent. */
    public function smsAvailable(): bool
    {
        // Ops switch (config partner_intake.sms, default on): false sends every intake code by email.
        return (bool) config('partner_intake.sms', true) && rescue(fn () => $this->otp->status() === 'CONFIGURED', false, false);
    }

    /**
     * Stores the uploaded files (magic-byte checked) as documents of the platform tenant and queues each for the malware
     * scan. Nothing reads them until the scan says CLEAN.
     *
     * @param  array<string, UploadedFile|null>  $files  kind => file
     * @return list<array{document_id: string, kind: string, name: string}>
     */
    public function storeDocuments(array $files, string $directory, string $categoryPrefix): array
    {
        $tenantId = $this->platformTenantId();
        $out = [];
        foreach ($files as $kind => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $mime = (string) $file->getMimeType();
            if (! in_array($mime, self::UPLOAD_MIMES, true) || ! FileSignature::fileMatches((string) $file->getRealPath(), $mime)) {
                throw ValidationException::withMessages([$kind => __('partner_apply.errors.file_type')]);
            }
            $sha = hash_file('sha256', (string) $file->getRealPath());
            $key = $file->storeAs($directory, $kind.'-'.Str::random(12).'.'.($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg')), 'local');
            $id = (string) Str::uuid();
            DB::table('documents')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'party_id' => null, 'category' => $categoryPrefix.'_'.strtoupper($kind), 'storage_key' => $key,
                'mime_type' => $mime, 'size_bytes' => (int) $file->getSize(), 'sha256' => $sha, 'scan_status' => DocumentScanQueue::PENDING_SCAN,
                'verification_status' => 'PENDING', 'ocr_data' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->scans->enqueue(Document::findOrFail($id), 'local');
            $out[] = ['document_id' => $id, 'kind' => (string) $kind, 'name' => Str::limit((string) $file->getClientOriginalName(), 120, '')];
        }

        return $out;
    }

    /** @param list<array{document_id: string}> $documents  @return array<string, string> document_id => scan_status */
    public function scanStatuses(array $documents): array
    {
        $ids = array_column($documents, 'document_id');

        return $ids === [] ? [] : DB::table('documents')->whereIn('id', $ids)->pluck('scan_status', 'id')->map(fn ($s) => (string) $s)->all();
    }

    /** Every uploaded file has a CLEAN verdict (held, infected or missing files block approval). */
    public function documentsClean(array $documents): bool
    {
        $statuses = $this->scanStatuses($documents);

        return $documents !== [] && count($statuses) === count($documents) && collect($statuses)->every(fn ($s) => $s === DocumentScanQueue::CLEAN);
    }

    /**
     * Issues a fresh one-time code to $destination (phone → SMS through the OTP service, email → mail) and stores its
     * hash on $record. Throttled per record (cooldown + a maximum number of sends).
     */
    public function sendCode(Model $record, string $destination, string $message, string $subject): void
    {
        if ((int) $record->code_sent_count >= self::MAX_CODE_SENDS) {
            throw ValidationException::withMessages(['code' => __('partner_apply.errors.too_many_codes')]);
        }
        if ($record->code_sent_at && $record->code_sent_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw ValidationException::withMessages(['code' => __('partner_apply.errors.wait_resend')]);
        }
        $code = (string) random_int(100000, 999999);
        $isEmail = str_contains($destination, '@');
        $record->forceFill([
            'verification_channel' => $isEmail ? 'EMAIL' : 'SMS', 'code_hash' => $this->codeHash($record, $code),
            'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES), 'code_attempts' => 0,
            'code_sent_count' => (int) $record->code_sent_count + 1, 'code_sent_at' => now(),
        ])->save();

        $text = str_replace(':code', $code, $message);
        if ($isEmail) {
            $this->mail($destination, $subject, $text);
        } else {
            SendOtpJob::send($destination, $code, $text, 'sms');
        }
    }

    /** Checks a code; wrong codes count towards the attempt limit, after which a new code must be requested. */
    public function checkCode(Model $record, string $code): bool
    {
        if ($record->code_hash === null || $record->code_expires_at === null || $record->code_expires_at->isPast()
            || (int) $record->code_attempts >= self::MAX_CODE_ATTEMPTS) {
            return false;
        }
        if (hash_equals((string) $record->code_hash, $this->codeHash($record, trim($code)))) {
            $record->forceFill(['code_hash' => null, 'code_expires_at' => null, 'verified_at' => now()])->save();

            return true;
        }
        $record->forceFill(['code_attempts' => (int) $record->code_attempts + 1])->save();

        return false;
    }

    public function mail(string $to, string $subject, string $body): void
    {
        try {
            Mail::to($to)->send(new NotificationMail($subject, $body));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function codeHash(Model $record, string $code): string
    {
        return hash_hmac('sha256', $record->getKey().'|'.$code, (string) config('app.key'));
    }
}
