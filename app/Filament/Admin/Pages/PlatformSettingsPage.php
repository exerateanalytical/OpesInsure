<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Audit\AuditWriter;
use App\Application\Settings\PlatformSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Admin-editable platform settings: support contacts (served to the app by
 * GET public/support-contacts), SMS/WhatsApp OTP providers, OTP routing,
 * the contact-verification switch and SMTP. Secret fields are never sent to
 * the browser; leaving one blank keeps the stored value.
 */
final class PlatformSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Platform settings';

    protected static ?string $slug = 'platform-settings';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.admin.pages.platform-settings';

    public const SECRETS = ['twilio_auth_token', 'etech_sms_password', 'etech_rest_token', 'mail_password'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'])->exists();
    }

    public function getTitle(): string
    {
        return 'Platform settings';
    }

    public function mount(): void
    {
        $row = app(PlatformSettings::class)->editable();
        $state = $row->attributesToArray();

        foreach (self::SECRETS as $secret) {
            unset($state[$secret]);
        }

        $this->form->fill([
            'twilio_enabled' => true,
            'etech_sms_enabled' => true,
            'etech_whatsapp_enabled' => true,
            'otp_channel_priority' => 'whatsapp,sms',
            'otp_provider_priority' => 'etech,twilio',
            'require_contact_verification' => false,
            'mail_enabled' => false,
            ...array_filter($state, fn ($v) => $v !== null),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $secretHint = fn (string $field) => fn () => filled(app(PlatformSettings::class)->editable()->getAttribute($field)) ? 'Saved. Leave blank to keep it.' : 'Not set.';

        return $schema->statePath('data')->components([
            Section::make('Regional')->description('REQ-TMP-003: platform default timezone for business dates. Organizations and branches may override it; empty = Africa/Douala (APP_TIMEZONE).')->columns(2)->schema([
                Select::make('default_timezone')->label('Default timezone')->options(fn () => app(\App\Application\Settings\TimezoneCatalogue::class)->options())->searchable()->placeholder('Africa/Douala (APP_TIMEZONE)'),
            ]),
            Section::make('Support contacts')->description('Shown in the mobile app (Terms, Support, Invitations, Access denied).')->columns(2)->schema([
                TextInput::make('support_email')->email()->maxLength(190),
                TextInput::make('support_phone')->tel()->maxLength(32)->placeholder('+2376...'),
                TextInput::make('whatsapp_number')->label('WhatsApp number')->tel()->maxLength(32)->placeholder('+2376...'),
                TextInput::make('partner_email')->label('Partner onboarding email')->email()->maxLength(190),
            ]),
            Section::make('Twilio')->columns(2)->schema([
                TextInput::make('twilio_account_sid')->label('Account SID')->maxLength(64),
                TextInput::make('twilio_auth_token')->label('Auth token')->password()->revealable()->maxLength(255)->helperText($secretHint('twilio_auth_token')),
                TextInput::make('twilio_sms_from')->label('SMS from number')->maxLength(32),
                TextInput::make('twilio_whatsapp_from')->label('WhatsApp sender')->maxLength(32),
                Toggle::make('twilio_enabled')->label('Enabled'),
            ]),
            Section::make('ETECH KEYS')->description('SMS: https://sms.etech-keys.com/ss/envoyer.php (login/password) or REST v1 with the bearer token (preferred when set). WhatsApp needs the bearer token and an approved template whose single body parameter is the code.')->columns(2)->schema([
                TextInput::make('etech_sms_login')->label('SMS login')->maxLength(120),
                TextInput::make('etech_sms_password')->label('SMS password')->password()->revealable()->maxLength(255)->helperText($secretHint('etech_sms_password')),
                TextInput::make('etech_sms_sender')->label('SMS sender name')->maxLength(11)->helperText('Max 11 characters.'),
                TextInput::make('etech_rest_token')->label('REST v1 bearer token')->password()->revealable()->maxLength(2000)->helperText($secretHint('etech_rest_token')),
                TextInput::make('etech_whatsapp_template_name')->label('WhatsApp template name')->maxLength(120),
                TextInput::make('etech_whatsapp_template_language')->label('WhatsApp template language')->maxLength(16)->placeholder('fr'),
                Toggle::make('etech_sms_enabled')->label('SMS enabled'),
                Toggle::make('etech_whatsapp_enabled')->label('WhatsApp enabled'),
            ]),
            Section::make('One-time codes')->columns(2)->schema([
                Select::make('otp_channel_priority')->label('Channel priority')->required()->options([
                    'whatsapp,sms' => 'WhatsApp, then SMS',
                    'sms,whatsapp' => 'SMS, then WhatsApp',
                    'sms' => 'SMS only',
                    'whatsapp' => 'WhatsApp only',
                ]),
                Select::make('otp_provider_priority')->label('Provider priority')->required()->options([
                    'etech,twilio' => 'ETECH KEYS, then Twilio',
                    'twilio,etech' => 'Twilio, then ETECH KEYS',
                    'etech' => 'ETECH KEYS only',
                    'twilio' => 'Twilio only',
                ]),
                Toggle::make('require_contact_verification')->label('Require phone/email verification at sign-up')
                    ->helperText('Off: new accounts are active immediately and contacts stay unverified. Turn on only once a delivery channel is proven to work.'),
            ]),
            Section::make('Mail (SMTP)')->columns(2)->schema([
                TextInput::make('mail_host')->maxLength(190),
                TextInput::make('mail_port')->numeric()->minValue(1)->maxValue(65535),
                TextInput::make('mail_username')->maxLength(190),
                TextInput::make('mail_password')->password()->revealable()->maxLength(255)->helperText($secretHint('mail_password')),
                Select::make('mail_encryption')->options(['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL']),
                TextInput::make('mail_from_address')->email()->maxLength(190),
                TextInput::make('mail_from_name')->maxLength(120),
                Toggle::make('mail_enabled')->label('Send email with these settings'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();

        foreach (self::SECRETS as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $row = app(PlatformSettings::class)->editable();
        $row->fill($data)->save();

        app(AuditWriter::class)->record('platform.settings.updated', 'user', (string) auth()->id(), ['fields' => array_keys($data)]);

        foreach (self::SECRETS as $secret) {
            $this->data[$secret] = null;
        }

        Notification::make()->title('Settings saved')->success()->send();
    }
}
