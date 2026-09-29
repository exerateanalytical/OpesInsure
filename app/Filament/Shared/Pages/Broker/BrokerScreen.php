<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\Partners\BookScope;
use App\Application\WebExperiences\{PortalAuthorization, PortalScope};
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Attributes\Locked;

/**
 * Base of the broker portal screens BRK-004 .. BRK-024 (launch 2026-10-02, docs/spec/PORTAL_WRITE_RULES.md):
 *  - /broker only; the screen opens for a holder of ANY of static::$permissions, each being the permission of the API
 *    route that serves the same data (read) or of the API write its actions call (PortalAuthorization::allowsRead,
 *    so the documented EQUIVALENT_READS count);
 *  - rows are the portal tenant's, narrowed to the caller's book (BookScope::parties / PortalScope::narrowTable);
 *  - every write is a shared workflow action (CrmLeadActions, PartyActions, KycActions) = same service + permission
 *    as the API, with the own-record check of WorkflowAction.
 * Labels: resources/lang/{en,fr}/broker_screens_a.php (<screen>.nav / .title / .intro).
 */
abstract class BrokerScreen extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.broker-screens-a';

    /** @var list<string> */
    protected static array $permissions = ['broker.portal.read'];

    protected static string $screen = '';

    /** broker_screens_a.groups.<key> */
    protected static string $group = 'crm';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        return PortalScope::panel() === 'broker' && static::allowedAny(static::$permissions);
    }

    /** @param  list<string>  $permissions */
    public static function allowedAny(array $permissions): bool
    {
        $user = auth()->user();

        return $user instanceof User && collect($permissions)->contains(fn (string $p): bool => PortalAuthorization::allowsRead($user, $p));
    }

    public static function getNavigationGroup(): ?string
    {
        return __('broker_screens_a.groups.'.static::$group);
    }

    public static function getNavigationLabel(): string
    {
        return __('broker_screens_a.'.static::$screen.'.nav');
    }

    public function getTitle(): string
    {
        return __('broker_screens_a.'.static::$screen.'.title');
    }

    public function getSubheading(): ?string
    {
        return __('broker_screens_a.'.static::$screen.'.intro');
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    /** @return list<array{key:string,label:string,value:string,tone:string}> */
    public function getStats(): array
    {
        return [];
    }

    /** @return list<array{heading:string, rows:array<string, ?string>}> */
    public function getDetailSections(): array
    {
        return [];
    }

    protected function tenant(): string
    {
        return $this->tenantId ?? '00000000-0000-0000-0000-000000000000';
    }

    /** Keep only the caller's book (party column); no-op for a tenant-wide caller. */
    protected static function inBook(EloquentBuilder|QueryBuilder $q, string $column = 'party_id'): EloquentBuilder|QueryBuilder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return $q->whereRaw('1 = 0');
        }
        $parties = app(BookScope::class)->parties($user);

        return $parties === null ? $q : $q->whereIn($column, $parties);
    }

    protected static function col(string $name, ?string $key = null): TextColumn
    {
        return TextColumn::make($name)->label(__('broker_screens_a.columns.'.($key ?? str_replace('.', '_', $name))))->placeholder('—');
    }

    /** Service-fed rows (stdClass / arrays) keyed for ->records(). */
    protected static function keyed(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $a = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : (is_array($v) || is_object($v) ? json_encode($v) : $v), (array) $r);
            $a['__key'] = (string) ($a['id'] ?? count($out));
            $out[$a['__key']] = $a;
        }

        return $out;
    }

    protected static function code(string $group, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (["broker_screens_a.codes.{$group}.{$value}", "broker_screens_a.codes.status.{$value}"] as $key) {
            if (__($key) !== $key) {
                return __($key);
            }
        }

        return ucfirst(strtolower(str_replace('_', ' ', $value)));
    }
}
