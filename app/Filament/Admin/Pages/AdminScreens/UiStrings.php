<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

/**
 * ADM-031 Localisation management (UI strings) — read-only: every interface string group in resources/lang (EN is
 * the reference), how many keys it has in French, and the keys still missing in French (plus the fr.json phrase
 * file). Strings are changed in the code base, reviewed like any other change; nothing is edited here.
 * Platform localisation roles (same as the document engine localisation screen).
 */
final class UiStrings extends AdminScreenPage
{
    public const ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-languages';

    protected static ?int $navigationSort = 52;

    protected static ?string $slug = 'platform/ui-strings';

    protected static string $screen = 'ui_strings';

    protected static string $group = 'Administration';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::ROLES)->exists();
    }

    /** @return array<string, array{group:string, en:int, fr:int, missing:list<string>}> */
    public static function report(): array
    {
        $out = [];
        foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
            $group = basename($file, '.php');
            $en = Arr::dot((array) require $file);
            $frFile = lang_path('fr/'.$group.'.php');
            $fr = is_file($frFile) ? Arr::dot((array) require $frFile) : [];
            $en = array_filter($en, fn ($v) => ! is_array($v));
            $missing = array_values(array_diff(array_keys($en), array_keys($fr)));
            $out[$group] = ['group' => $group, 'en' => count($en), 'fr' => count(array_intersect_key($fr, $en)), 'missing' => $missing];
        }
        $json = json_decode((string) @file_get_contents(lang_path('fr.json')), true) ?: [];
        $out['fr.json'] = ['group' => 'fr.json', 'en' => count($json), 'fr' => count(array_filter($json, fn ($v) => is_string($v) && $v !== '')), 'missing' => array_keys(array_filter($json, fn ($v) => ! is_string($v) || $v === ''))];
        ksort($out);

        return $out;
    }

    public function kpis(): array
    {
        $r = collect(self::report());
        $en = $r->sum('en');
        $missing = $r->sum(fn ($g) => count($g['missing']));

        return [
            self::kpi('string_groups', $r->count()),
            self::kpi('strings', $en),
            self::kpi('missing_fr', $missing, $missing > 0 ? 'warning' : 'success'),
            self::kpi('fr_coverage', $en > 0 ? round(($en - $missing) * 100 / $en, 1).' %' : '—', $missing > 0 ? 'warning' : 'success'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int|string $page, int|string $recordsPerPage) {
                $rows = [];
                foreach (self::report() as $g => $r) {
                    $rows[$g] = ['__key' => $g, 'id' => $g, 'group' => $g, 'en' => $r['en'], 'fr' => $r['fr'], 'missing_count' => count($r['missing']),
                        'coverage' => $r['en'] > 0 ? round(($r['en'] - count($r['missing'])) * 100 / $r['en'], 1).' %' : '—'];
                }
                uasort($rows, fn ($a, $b) => $b['missing_count'] <=> $a['missing_count'] ?: strcmp($a['group'], $b['group']));

                return self::pageOf($rows, $search, ['group'], $page, $recordsPerPage);
            })
            ->columns([
                TextColumn::make('group')->label(self::col('string_group'))->searchable()->fontFamily('mono'),
                TextColumn::make('en')->label(self::col('en_strings'))->numeric(),
                TextColumn::make('fr')->label(self::col('fr_strings'))->numeric(),
                TextColumn::make('missing_count')->label(self::col('missing_fr'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'success'),
                TextColumn::make('coverage')->label(self::col('coverage')),
            ])
            ->recordActions([
                Action::make('missingStrings')->label(__('admin_screens.missingStrings.label'))->icon('lucide-list')->color('gray')
                    ->visible(fn (array $record) => $record['missing_count'] > 0)
                    ->modalHeading(fn (array $record) => $record['group'])->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('filament.admin.pages.partials.missing-strings', ['keys' => self::report()[$record['group']]['missing'] ?? []])),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
