<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;

/**
 * UI audit 2026-09-27 (docs/UI_AUDIT_ADMIN_INSURER_2026-09-27.md): ONE place that makes the sidebar of every
 * panel consistent, instead of editing ~130 resource files owned by different batches (same approach as
 * LucideIcons):
 *  - labels and group names are sentence case ("Compliance Cases" → "Compliance cases"; acronyms kept);
 *  - lang/{locale}/navigation.php renames ambiguous entries ("group / label" keys first, then "label") and gives
 *    the French label, so a French user no longer gets an English sidebar. Labels that are already translated
 *    through __() are left alone (no matching key).
 * Bound in place of Filament's scoped NavigationManager by WebExperienceServiceProvider.
 */
class LocalizedNavigationManager extends NavigationManager
{
    /** @return array<NavigationGroup> */
    public function get(): array
    {
        $groups = parent::get();
        foreach ($groups as $group) {
            $groupKey = self::sentenceCase((string) $group->getLabel());
            foreach ($group->getItems() as $item) {
                if ($item instanceof NavigationItem) {
                    $this->relabel($item, $groupKey);
                }
            }
            if (filled($group->getLabel())) {
                $group->label(self::translate('groups', $groupKey));
            }
        }

        return $groups;
    }

    private function relabel(NavigationItem $item, string $groupKey): void
    {
        $label = self::sentenceCase((string) $item->getLabel());
        $labels = (array) trans('navigation.labels');
        $item->label($labels[$groupKey.' / '.$label] ?? $labels[$label] ?? $label);
        foreach ($item->getChildItems() as $child) {
            if ($child instanceof NavigationItem) {
                $this->relabel($child, $groupKey);
            }
        }
    }

    private static function translate(string $section, string $key): string
    {
        $map = (array) trans('navigation.'.$section);

        return $map[$key] ?? $key;
    }

    /** "Payment Intent Records" → "Payment intent records"; keeps acronyms (CIMA, QR, FR/EN) and the first word. */
    public static function sentenceCase(string $label): string
    {
        $words = explode(' ', trim($label));
        foreach ($words as $i => $w) {
            if ($i > 0 && preg_match('/^\p{Lu}\p{Ll}+$/u', $w) === 1) {
                $words[$i] = mb_strtolower($w);
            }
        }

        return implode(' ', $words);
    }
}
