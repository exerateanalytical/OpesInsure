<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\ClaimsWorkbench;

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Models\User;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * CLP-001 claims professional / adjuster dashboard + CLP-018 adjuster performance (own figures only).
 * Expert work (claims.experts.work): assignments per stage, inspections due in 7 days, items waiting on the adjuster,
 * performance. Claim handlers (claims.view): the open claims assigned to them. Both narrowed to the caller's carrier.
 */
final class ClaimsWorkbenchDashboard extends WorkbenchPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-layout-dashboard';

    protected static ?int $navigationSort = 78;

    protected static ?string $slug = 'adjuster-workbench';

    protected static string $screen = 'dashboard';

    protected static array $permissions = [self::WORK, 'claims.view'];

    public function content(Schema $schema): Schema
    {
        $user = auth()->user();
        if (! $user instanceof User || $this->tenantId === null) {
            return $schema->components([]);
        }
        $wb = app(AdjusterWorkbench::class);
        $sections = [];
        if (self::may(self::WORK)) {
            $d = $wb->dashboard($this->tenantId, $user);
            $stats = [self::entry('open', $d['open'], self::t('dashboard.open'))->size('lg')->weight('bold')];
            foreach ($d['counts'] as $status => $n) {
                $stats[] = self::entry('stage_'.$status, $n, self::t('stages.'.$status))->url(MyAssignments::getUrl(['status' => $status]));
            }
            $sections[] = Section::make(self::t('dashboard.pipeline'))->icon('lucide-hard-hat')->columns(['default' => 2, 'md' => 4])->schema($stats)
                ->headerActions([\Filament\Actions\Action::make('allAssignments')->label(self::t('nav.assignments'))->url(MyAssignments::getUrl())->link()]);
            $sections[] = Section::make(self::t('dashboard.upcoming'))->icon('lucide-calendar-clock')->schema([self::rows('upcoming', $d['upcoming'])]);
            $sections[] = Section::make(self::t('dashboard.attention'))->icon('lucide-bell-ring')->schema([self::rows('attention', $d['attention'])]);
            $sections[] = self::performanceSection($wb->performance($this->tenantId, $user));
        }
        if (self::may('claims.view')) {
            $h = $wb->handledClaims($this->tenantId, $user);
            $sections[] = Section::make(self::t('dashboard.my_claims'))->icon('lucide-shield-alert')->schema([
                RepeatableEntry::make('wb_claims')->hiddenLabel()->placeholder(self::t('empty'))->columns(['default' => 1, 'md' => 5])
                    ->state(array_map(fn ($c) => ['id' => $c->id, 'claim_number' => $c->claim_number, 'status' => $c->status, 'priority' => $c->priority,
                        'loss_occurred_at' => $c->loss_occurred_at, 'reserve' => \App\Application\WebExperiences\Money::format($c->current_reserve_minor === null ? null : (int) $c->current_reserve_minor, $c->currency)], $h['rows']))
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('claim_number')->label(self::t('fields.claim_number'))
                            ->url(fn ($state, $component) => rescue(fn () => ClaimResource::getUrl('view', ['record' => data_get($component->getContainer()->getConstantState(), 'id')]), null, false)),
                        \Filament\Infolists\Components\TextEntry::make('status')->label(self::t('fields.status'))->badge(),
                        \Filament\Infolists\Components\TextEntry::make('priority')->label(self::t('fields.priority'))->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('loss_occurred_at')->label(self::t('fields.loss_occurred_at'))->dateTime()->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('reserve')->label(self::t('fields.reserve')),
                    ]),
            ]);
        }

        return $schema->components($sections);
    }

    /** CLP-018 adjuster performance. */
    public static function performanceSection(array $p): Section
    {
        $days = fn (?float $d) => $d === null ? null : self::t('days', ['n' => $d]);

        return Section::make(self::t('performance.title'))->icon('lucide-gauge')->description(self::t('performance.help'))->columns(['default' => 2, 'md' => 4])->schema([
            self::entry('p_total', $p['total'], self::t('performance.total')),
            self::entry('p_open', $p['open'], self::t('performance.open')),
            self::entry('p_completed', $p['completed'], self::t('performance.completed')),
            self::entry('p_declined', $p['declined'], self::t('performance.declined')),
            self::entry('p_cancelled', $p['cancelled'], self::t('performance.cancelled')),
            self::entry('p_reports', $p['reports_submitted'], self::t('performance.reports_submitted')),
            self::entry('p_returned', $p['reports_returned'], self::t('performance.reports_returned')),
            self::entry('p_ftr', $p['first_time_right_pct'] === null ? null : $p['first_time_right_pct'].' %', self::t('performance.first_time_right')),
            self::entry('p_accept', $days($p['avg_days_to_accept']), self::t('performance.avg_days_to_accept')),
            self::entry('p_inspect', $days($p['avg_days_to_inspect']), self::t('performance.avg_days_to_inspect')),
            self::entry('p_report', $days($p['avg_days_to_report']), self::t('performance.avg_days_to_report')),
            self::entry('p_review', $days($p['avg_days_to_review']), self::t('performance.avg_days_to_review')),
            self::entry('p_sla', $p['sla_breaches'], self::t('performance.sla_breaches')),
            self::entry('p_asm', $p['assessments_recorded'], self::t('performance.assessments_recorded')),
            self::entry('p_asm_ok', $p['assessments_accepted'], self::t('performance.assessments_accepted')),
        ]);
    }

    /** @param list<object> $rows */
    private static function rows(string $key, array $rows): RepeatableEntry
    {
        return RepeatableEntry::make('wb_'.$key)->hiddenLabel()->placeholder(self::t('empty'))->columns(['default' => 1, 'md' => 4])
            ->state(array_map(fn ($r) => ['id' => $r->id, 'claim_number' => $r->claim_number, 'status' => $r->status,
                'inspection_scheduled_for' => $r->inspection_scheduled_for, 'loss_location' => $r->inspection_location ?? $r->loss_location], $rows))
            ->schema([
                \Filament\Infolists\Components\TextEntry::make('claim_number')->label(self::t('fields.claim_number'))
                    ->url(fn ($component) => AssignmentWorkbench::getUrl(['assignment' => data_get($component->getContainer()->getConstantState(), 'id')])),
                \Filament\Infolists\Components\TextEntry::make('status')->label(self::t('fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => self::t('stages.'.$state)),
                \Filament\Infolists\Components\TextEntry::make('inspection_scheduled_for')->label(self::t('fields.inspection_scheduled_for'))->dateTime()->placeholder('—'),
                \Filament\Infolists\Components\TextEntry::make('loss_location')->label(self::t('fields.location'))->placeholder('—'),
            ]);
    }
}
