<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Distribution;

use App\Application\CarrierOperations\Agreements\BulkAgreementImporter;
use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

/**
 * S10 — bulk carrier–broker agreement setup (/admin platform staff, /insurer own carrier). Permissions are those of
 * the agreement API: view = distribution.agreements.view, upload/create/submit = distribution.agreements.manage,
 * activate = distribution.agreements.approve (a different user than the maker/submitter).
 */
final class BulkAgreements extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-spreadsheet';

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'bulk-agreements';

    protected string $view = 'filament.admin.pages.distribution.bulk-agreements';

    public $upload = null;

    public ?string $fileName = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public string $reason = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('distribution.agreements.view');
    }

    public static function getNavigationGroup(): ?string { return __('web_experience.sections.group_distribution'); }

    public static function getNavigationLabel(): string { return __('bulk_agreements.title'); }

    public function getTitle(): string { return __('bulk_agreements.title'); }

    public static function scope(): array
    {
        if (PortalScope::panel() === 'insurer') {
            return ['carrier' => PortalScope::carrierId() ?? '00000000-0000-0000-0000-000000000000', 'tenant' => null];
        }
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return ['carrier' => null, 'tenant' => app(PlatformAuthority::class)->isPlatformTenant($tenant) ? null : $tenant];
    }

    public function can(string $permission): bool
    {
        return (bool) auth()->user()?->hasPermission('distribution.agreements.'.$permission);
    }

    public function preview(): void
    {
        abort_unless($this->can('manage'), 403);
        $this->validate(['upload' => 'required|file|max:5120']);
        $this->fileName = $this->upload->getClientOriginalName();
        try {
            $this->rows = app(BulkAgreementImporter::class)->read($this->upload->getRealPath(), $this->fileName);
            $this->results = app(BulkAgreementImporter::class)->validate($this->rows, self::scope());
        } catch (ValidationException $e) {
            $this->rows = $this->results = [];
            Notification::make()->danger()->title(collect($e->errors())->flatten()->implode(' '))->send();
        }
    }

    public function createDrafts(): void
    {
        abort_unless($this->can('manage'), 403);
        try {
            app(BulkAgreementImporter::class)->createDrafts(auth()->user(), $this->rows, self::scope(), $this->fileName);
            Notification::make()->success()->title(__('bulk_agreements.drafts_created', ['count' => count($this->rows)]))->send();
            $this->rows = $this->results = [];
            $this->upload = null;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->implode(' '))->send();
        }
    }

    public function submitBatch(string $id): void
    {
        abort_unless($this->can('manage'), 403);
        $this->run(fn () => app(BulkAgreementImporter::class)->submit(auth()->user(), $id, self::scope()), 'bulk_agreements.submitted');
    }

    public function activateBatch(string $id): void
    {
        abort_unless($this->can('approve'), 403);
        if (mb_strlen(trim($this->reason)) < 5) {
            Notification::make()->danger()->title(__('bulk_agreements.errors.reason'))->send();

            return;
        }
        $this->run(fn () => app(BulkAgreementImporter::class)->activate(auth()->user(), $id, trim($this->reason), self::scope()), 'bulk_agreements.activated');
    }

    private function run(callable $fn, string $done): void
    {
        try {
            $b = $fn();
            Notification::make()->{$b->status === 'PARTIAL' ? 'warning' : 'success'}()->title(__($b->status === 'PARTIAL' ? 'bulk_agreements.partial' : $done))->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->implode(' '))->send();
        }
    }

    protected function getViewData(): array
    {
        $scope = self::scope();

        return [
            'columns' => BulkAgreementImporter::COLUMNS,
            'hasErrors' => collect($this->results)->contains(fn ($r) => $r['errors'] !== []),
            'batches' => app(BulkAgreementImporter::class)->batches($scope)->map(function ($b) {
                $b->agreement_ids = (array) json_decode((string) $b->agreement_ids, true);
                $b->activation_errors = (array) json_decode((string) $b->activation_errors, true);

                return $b;
            }),
            'canManage' => $this->can('manage'), 'canApprove' => $this->can('approve'), 'me' => auth()->id(),
        ];
    }
}
