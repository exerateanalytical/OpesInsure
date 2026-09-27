<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\Audit\AuditWriter;
use App\Application\Settings\TimezoneCatalogue;
use App\Application\Tenancy\OrganizationStructureService;
use App\Application\Temporal\TimezoneResolver;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\DocumentNumberingFamily;
use App\Models\Tenant;
use App\Models\TenantBranch;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Tenant / branch / user settings (web UI phase 4, REQ-TMP-003): my language and display timezone, the organisation's
 * timezone and language, each branch's timezone, a link to business hours, and the document numbering prefixes in
 * force for the organisation (read-only; edited under Document numbering). Stored where they already live
 * (users, tenants, tenant_branches) through OrganizationStructureService, audited. The admin panel may pick any
 * organisation; portal panels are fixed to the tenant of the membership (see subclasses).
 */
abstract class OrganizationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-sliders-horizontal';

    public static function getNavigationLabel(): string
    {
        return __('organisation_settings.title');
    }

    protected static ?string $slug = 'organisation-settings';

    protected string $view = 'filament.shared.pages.organisation-settings';

    public const LOCALES = ['fr' => 'Français', 'en' => 'English'];

    /** Server-side only (a client cannot switch organisation by editing the Livewire payload). */
    #[\Livewire\Attributes\Locked]
    public ?string $tenantId = null;

    public ?array $data = [];

    /** Whether the signed-in user may change the organisation / branch settings of $tenantId. */
    abstract protected function canManageTenant(): bool;

    abstract protected function resolveTenantId(): ?string;

    protected function tenantSelectable(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return __('organisation_settings.title');
    }

    public function mount(): void
    {
        $this->tenantId = $this->resolveTenantId();
        $this->fillState();
    }

    public function fillState(): void
    {
        $user = auth()->user();
        $tenant = $this->tenantId ? Tenant::find($this->tenantId) : null;
        $this->form->fill([
            'tenant_id' => $this->tenantId,
            'locale' => $user?->locale, 'display_timezone' => $user?->display_timezone,
            'tenant_timezone' => $tenant?->timezone, 'tenant_locale' => $tenant?->primary_locale,
            'branches' => $tenant ? TenantBranch::where('tenant_id', $tenant->id)->orderBy('name')->get()
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name.' ('.$b->code.')', 'timezone' => $b->timezone])->all() : [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $tz = fn () => app(TimezoneCatalogue::class)->options();
        $manage = fn () => $this->canManageTenant();

        return $schema->statePath('data')->components([
            Section::make(__('organisation_settings.my_preferences'))->columns(2)->schema([
                Select::make('locale')->label(__('organisation_settings.language'))->options(self::LOCALES)->required(),
                Select::make('display_timezone')->label(__('organisation_settings.display_timezone'))->options($tz)->searchable()->placeholder(__('organisation_settings.follow')),
            ]),
            Section::make(__('organisation_settings.organisation'))->columns(3)->schema([
                Select::make('tenant_id')->label(__('organisation_settings.organisation'))->options(fn () => Tenant::orderBy('legal_name')->pluck('legal_name', 'id')->all())->searchable()
                    ->visible(fn () => $this->tenantSelectable())->live()->dehydrated(false)
                    ->afterStateUpdated(function ($state) {
                        $this->tenantId = $state;
                        $this->fillState();
                    }),
                Select::make('tenant_timezone')->label(__('organisation_settings.organisation_timezone'))->options($tz)->searchable()->placeholder(__('organisation_settings.platform_default'))->disabled(fn () => ! $manage()),
                Select::make('tenant_locale')->label(__('organisation_settings.primary_language'))->options(self::LOCALES)->disabled(fn () => ! $manage()),
                Placeholder::make('effective')->label(__('organisation_settings.effective_timezone'))->content(fn () => $this->tenantId ? app(TimezoneResolver::class)->forTenant($this->tenantId) : '—'),
            ])->visible(fn () => $this->tenantId !== null || $this->tenantSelectable()),
            Section::make(__('organisation_settings.branches'))->description(__('organisation_settings.branches_hint'))->visible(fn () => $this->tenantId !== null)->schema([
                Repeater::make('branches')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(2)->disabled(fn () => ! $manage())->schema([
                    Hidden::make('id'),
                    TextInput::make('name')->label(__('organisation_settings.name'))->disabled()->dehydrated(false),
                    Select::make('timezone')->label(__('organisation_settings.timezone'))->options($tz)->searchable()->placeholder(__('organisation_settings.inherit')),
                ]),
                Placeholder::make('business_hours')->hiddenLabel()->content(fn () => \Filament\Facades\Filament::getCurrentOrDefaultPanel()->getId() === 'admin'
                    ? new HtmlString(e(__('organisation_settings.hours_admin')).' <a class="text-primary-600 underline" href="'
                        .e(\App\Filament\Admin\Resources\BusinessHours\BusinessHoursResource::getUrl('index', panel: 'admin')).'">'.e(__('organisation_settings.business_hours')).'</a>.')
                    : __('organisation_settings.hours_portal')),
            ]),
            Section::make(__('organisation_settings.numbering'))->visible(fn () => $this->tenantId !== null)->schema([
                Placeholder::make('numbering')->hiddenLabel()->content(fn () => $this->numberingHtml()),
            ]),
        ]);
    }

    /** @return list<array{family: string, prefix: string, include_year: bool, pad: int, scope: string}> Families in force (tenant override first). */
    public function numbering(): array
    {
        return DocumentNumberingFamily::where('status', 'ACTIVE')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenantId))
            ->orderByRaw('tenant_id IS NULL')->get()->unique('family_code')->sortBy('family_code')
            ->map(fn ($f) => ['family' => $f->family_code, 'prefix' => $f->prefix, 'include_year' => (bool) $f->include_year, 'pad' => (int) $f->pad,
                'scope' => $f->tenant_id ? __('organisation_settings.scope_org') : __('organisation_settings.scope_platform')])->values()->all();
    }

    private function numberingHtml(): HtmlString
    {
        $rows = $this->numbering();
        if ($rows === []) {
            return new HtmlString('<span class="text-sm text-gray-500">'.e(__('organisation_settings.numbering_none')).'</span>');
        }
        $html = '<table class="text-sm"><thead><tr class="text-left"><th class="pe-4">'.e(__('organisation_settings.family')).'</th><th class="pe-4">'.e(__('organisation_settings.example')).'</th><th>'.e(__('organisation_settings.scope')).'</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $example = $r['prefix'].'-'.($r['include_year'] ? now()->format('Y').'-' : '').str_pad('1', $r['pad'], '0', STR_PAD_LEFT);
            $html .= '<tr><td class="pe-4 font-mono">'.e($r['family']).'</td><td class="pe-4 font-mono">'.e($example).'</td><td>'.e($r['scope']).'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);
        $d = $this->form->getState();
        $catalogue = app(TimezoneCatalogue::class);
        foreach (['display_timezone', 'tenant_timezone'] as $k) {
            if (! empty($d[$k]) && ! $catalogue->isValid($d[$k])) {
                throw ValidationException::withMessages(['data.'.$k => __('organisation_settings.unknown_timezone')]);
            }
        }

        // User preferences (same audit event as PUT /organization/timezones/me).
        if ($user->display_timezone !== ($d['display_timezone'] ?? null) || $user->locale !== $d['locale']) {
            $before = ['locale' => $user->locale, 'display_timezone' => $user->display_timezone];
            $user->forceFill(['locale' => $d['locale'], 'display_timezone' => $d['display_timezone'] ?? null])->save();
            app(AuditWriter::class)->record('user.display_timezone.changed', 'user', $user->id, ['from' => $before, 'to' => ['locale' => $d['locale'], 'display_timezone' => $d['display_timezone'] ?? null]]);
        }

        if ($this->tenantId && $this->canManageTenant()) {
            $ok = ServiceValidation::run(function () use ($d, $user) {
                $svc = app(OrganizationStructureService::class);
                $tenant = Tenant::findOrFail($this->tenantId);
                if ($tenant->timezone !== ($d['tenant_timezone'] ?? null)) {
                    $svc->setTenantTimezone($tenant->id, $d['tenant_timezone'] ?? null, $user->id);
                }
                if (! empty($d['tenant_locale']) && $tenant->primary_locale !== $d['tenant_locale']) {
                    DB::table('tenants')->where('id', $tenant->id)->update(['primary_locale' => $d['tenant_locale'], 'updated_at' => now()]);
                    app(AuditWriter::class)->record('tenant.primary_locale.changed', 'tenant', $tenant->id, ['from' => $tenant->primary_locale, 'to' => $d['tenant_locale']]);
                }
                foreach ((array) ($d['branches'] ?? []) as $row) {
                    $branch = TenantBranch::where('tenant_id', $tenant->id)->find($row['id'] ?? null);
                    if ($branch && $branch->timezone !== ($row['timezone'] ?? null)) {
                        $svc->updateBranch($branch, ['timezone' => $row['timezone'] ?? null]);
                    }
                }

                return true;
            });
            if (! $ok) {
                return;
            }
        }

        $this->fillState();
        Notification::make()->title(__('organisation_settings.saved'))->success()->send();
    }
}
