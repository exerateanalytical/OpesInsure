<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * DEV-004 API Credentials (key management). Partners issue / revoke SANDBOX keys of their own client; production
 * credentials stay with platform staff. The secret is held in a NON-public property: rendered in the response of the
 * issue action only, never serialised into the Livewire snapshot, never stored (Passport keeps a hash) nor logged.
 */
final class CredentialsPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-key-round';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'credentials';

    protected static string $screen = 'credentials';

    public string $label = '';

    /** @var array{client_id:string, client_secret:string}|null shown once */
    protected ?array $issued = null;

    public function extraView(): ?string
    {
        return 'developer-portal.credentials';
    }

    /** @return array{client_id:string, client_secret:string}|null */
    public function issuedCredential(): ?array
    {
        return $this->issued;
    }

    public function canManageKeys(): bool
    {
        return in_array($this->link()->role, \App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService::KEY_MANAGERS, true);
    }

    public function issueKey(): void
    {
        $this->validate(['label' => 'nullable|string|max:120']);
        try {
            $r = $this->svc()->issueSandboxKey($this->client(), $this->link(), $this->label, auth()->user());
            $this->issued = ['client_id' => $r['client_id'], 'client_secret' => $r['client_secret']];
            $this->label = '';
            $this->state = 'SUCCESS';
            $this->stateMessage = __('developer_portal.ui.key_issued');
        } catch (ValidationException $e) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = (string) collect($e->errors())->flatten()->first();
        }
    }

    public function revokeKey(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }
        try {
            $this->svc()->revokeKey($this->client(), $this->link(), $id, auth()->user());
            $this->state = 'SUCCESS';
            $this->stateMessage = __('developer_portal.ui.key_revoked');
        } catch (ValidationException $e) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = (string) collect($e->errors())->flatten()->first();
        } catch (HttpExceptionInterface) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = __('developer_portal.ui.not_found');
        }
    }

    protected function cards(): array
    {
        $c = $this->client();

        return ['primary_client_id' => $c->oauth_client_id, 'environment' => $c->environment, 'connection_status' => $c->status];
    }

    protected function rows(): array
    {
        return array_map(fn ($k) => (array) $k, $this->svc()->keys($this->client()));
    }

    protected function columns(): array
    {
        return ['environment', 'oauth_client_id', 'label', 'status', 'last_used_at', 'created_at', 'revoked_at'];
    }

    public function rowActions(array $row): array
    {
        return $this->canManageKeys() && ($row['status'] ?? null) === 'ACTIVE' && ($row['environment'] ?? null) === 'sandbox'
            ? [['label' => __('developer_portal.ui.revoke'), 'action' => 'revokeKey', 'arg' => (string) $row['id']]] : [];
    }
}
