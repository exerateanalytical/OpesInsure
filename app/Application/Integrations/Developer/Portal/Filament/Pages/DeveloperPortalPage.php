<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService;
use App\Models\IntegrationClient;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Throwable;

/**
 * Shared developer-portal screen: title from developer_portal.screens.<key> (EN/FR), summary cards, one table and an
 * optional extra partial. The integration client is ALWAYS the signed-in user's own (integration_client_developers);
 * a switch is only offered between the user's own clients.
 */
abstract class DeveloperPortalPage extends Page
{
    protected string $view = 'developer-portal.page';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-code';

    /** developer_portal.screens.<key>. */
    protected static string $screen = 'home';

    public ?string $state = null;

    public ?string $stateMessage = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && app(PartnerDeveloperPortalService::class)->link($u) !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('developer_portal.screens.'.static::$screen);
    }

    public function getTitle(): string
    {
        return __('developer_portal.screens.'.static::$screen);
    }

    protected function svc(): PartnerDeveloperPortalService
    {
        return app(PartnerDeveloperPortalService::class);
    }

    /** The user's developer link for the active client (role + client id). */
    public function link(): object
    {
        $wanted = request()->hasSession() ? request()->session()->get('developer_portal.client_id') : null;
        $link = $this->svc()->link(auth()->user(), is_string($wanted) ? $wanted : null);
        abort_if($link === null, 403);

        return $link;
    }

    public function client(): IntegrationClient
    {
        return IntegrationClient::findOrFail($this->link()->integration_client_id);
    }

    /** Own clients only (switcher). @return array<string, string> */
    public function clientOptions(): array
    {
        return collect($this->svc()->links(auth()->user()))->mapWithKeys(fn ($l) => [$l->integration_client_id => $l->name])->all();
    }

    public function switchClient(string $id): void
    {
        if (array_key_exists($id, $this->clientOptions()) && request()->hasSession()) {
            request()->session()->put('developer_portal.client_id', $id);
        }
    }

    public function extraView(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    protected function cards(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function columns(): array
    {
        return [];
    }

    /** Per-row actions: ['label' => …, 'action' => livewire method, 'arg' => …]. @param array<string, mixed> $row @return list<array<string, string>> */
    public function rowActions(array $row): array
    {
        return [];
    }

    public function label(string $key): string
    {
        $t = __($k = 'developer_portal.columns.'.$key);

        return $t !== $k ? $t : ucfirst(str_replace('_', ' ', $key));
    }

    public function cell(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return __('developer_portal.ui.'.($value ? 'yes' : 'no'));
        }
        if (! is_scalar($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}.*)?$/', $value, $m)) {
            return rescue(function () use ($value, $m) {
                $d = CarbonImmutable::parse($value);

                return isset($m[1]) ? $d->setTimezone(app(\App\Application\Temporal\TimezoneResolver::class)->forUser(auth()->user()))->format('d/m/Y H:i') : $d->format('d/m/Y');
            }, (string) $value, false);
        }

        return (string) $value;
    }

    /** @return array{cards: array<string, mixed>, columns: list<string>, rows: list<array<string, mixed>>, state: string, message: string} */
    public function viewPayload(): array
    {
        try {
            $rows = array_map(fn ($r) => (array) $r, $this->rows());
            $cards = $this->cards();
            $state = $this->state ?? ($rows === [] ? 'EMPTY' : 'SUCCESS');
        } catch (Throwable $e) {
            report($e);
            [$rows, $cards, $state] = [[], [], 'ERROR'];
        }
        $cols = $this->columns() ?: array_keys($rows[0] ?? []);

        return ['cards' => $cards, 'columns' => $cols, 'rows' => $rows, 'state' => $state, 'message' => $this->stateMessage ?? __('developer_portal.states.'.$state)];
    }
}
