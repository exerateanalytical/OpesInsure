<?php

declare(strict_types=1);

/**
 * Launch 2026-10-02 (R5): Cameroon users are mostly francophone, so every English translation key must exist in
 * French — every nested key of every resources/lang/en/*.php group, and fr.json must stay valid JSON.
 */
function launchFrFlatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        if (is_array($v)) {
            $out += launchFrFlatten($v, $prefix.$k.'.');
        } else {
            $out[$prefix.$k] = $v;
        }
    }

    return $out;
}

it('has a French file with every key for every English lang group', function () {
    $missing = [];
    foreach (glob(lang_path('en/*.php')) as $file) {
        $group = basename($file, '.php');
        $frFile = lang_path("fr/{$group}.php");
        if (! is_file($frFile)) {
            $missing[] = "fr/{$group}.php (file missing)";

            continue;
        }
        $en = launchFrFlatten((array) require $file);
        $fr = launchFrFlatten((array) require $frFile);
        foreach (array_keys(array_diff_key($en, $fr)) as $key) {
            $missing[] = "{$group}.{$key}";
        }
    }

    expect($missing)->toBe([], 'Missing French keys: '.implode(', ', $missing));
});

it('has no empty French value where English has text, and a valid fr.json', function () {
    $empty = [];
    foreach (glob(lang_path('fr/*.php')) as $file) {
        $enFile = lang_path('en/'.basename($file));
        $en = is_file($enFile) ? launchFrFlatten((array) require $enFile) : [];
        foreach (launchFrFlatten((array) require $file) as $k => $v) {
            // Deliberate blanks (grammar suffixes, icon-only columns) are blank in English too.
            if (is_string($v) && trim($v) === '' && trim((string) ($en[$k] ?? '')) !== '' && ! str_ends_with((string) $k, 'suffix')) {
                $empty[] = basename($file, '.php').'.'.$k;
            }
        }
    }
    expect($empty)->toBe([]);

    $json = json_decode((string) file_get_contents(lang_path('fr.json')), true);
    expect($json)->toBeArray()->not->toBeEmpty();
});

it('shows panel dates as dd/mm/yyyy', function () {
    \App\Filament\Shared\Columns::applyDefaults();
    $table = \Filament\Tables\Table::make(new class extends \Livewire\Component implements \Filament\Tables\Contracts\HasTable
    {
        use \Filament\Tables\Concerns\InteractsWithTable;
        use \Filament\Actions\Concerns\InteractsWithActions;
        use \Filament\Schemas\Concerns\InteractsWithSchemas;
    });

    expect($table->getDefaultDateDisplayFormat())->toBe('d/m/Y')
        ->and($table->getDefaultDateTimeDisplayFormat())->toBe('d/m/Y H:i')
        ->and(config('app.timezone'))->toBe('Africa/Douala');
});
