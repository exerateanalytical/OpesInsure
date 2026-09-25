<?php

declare(strict_types=1);

namespace App\Models;

use App\Application\Settings\PlatformSettings;
use Illuminate\Database\Eloquent\Model;

final class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $guarded = ['id'];

    protected $hidden = ['twilio_auth_token', 'etech_sms_password', 'etech_rest_token', 'mail_password'];

    protected function casts(): array
    {
        return [
            'twilio_auth_token' => 'encrypted',
            'etech_sms_password' => 'encrypted',
            'etech_rest_token' => 'encrypted',
            'mail_password' => 'encrypted',
            'twilio_enabled' => 'boolean',
            'etech_sms_enabled' => 'boolean',
            'etech_whatsapp_enabled' => 'boolean',
            'require_contact_verification' => 'boolean',
            'mail_enabled' => 'boolean',
            'mail_port' => 'integer',
            'supported_locales' => 'array', // REQ-SET-001
            'setup_completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saved(fn () => app(PlatformSettings::class)->flush());
        self::deleted(fn () => app(PlatformSettings::class)->flush());
    }
}
