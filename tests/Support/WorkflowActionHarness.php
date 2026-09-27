<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

/**
 * Minimal Livewire host for the shared workflow actions (App\Filament\Shared\Actions) so each action is tested through
 * Filament (form validation, authorization, action closure) independently of the page that mounts it.
 *
 *   WorkflowActionHarness::$actions = [fn () => ClaimActions::close()];
 *   Livewire::test(WorkflowActionHarness::class, ['model' => Claim::class, 'recordId' => $id])->callAction('claimClose', [...]);
 */
final class WorkflowActionHarness extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var list<Closure> */
    public static array $actions = [];

    public ?string $model = null;

    public ?string $recordId = null;

    public function booted(): void
    {
        $record = $this->model && $this->recordId ? ($this->model)::query()->find($this->recordId) : null;
        foreach (self::$actions as $factory) {
            $action = $factory();
            if ($record) {
                $action->record($record);
            }
            $this->cacheAction($action);
        }
    }

    public function render(): string
    {
        return '<div><x-filament-actions::modals /></div>';
    }
}
