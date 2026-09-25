<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use ReflectionProperty;

/**
 * Owner decision D3 (canonical UI handoff: Lucide outline icons, 2 px stroke).
 * Package: mallardduck/blade-lucide-icons (prefix "lucide-").
 *
 * One central mapping instead of editing 100+ resource files other agents own:
 *  - the panel chrome icons (sidebar, top bar, user menu, search) via Filament
 *    icon aliases;
 *  - every registered resource/page navigation icon that is a Heroicon enum is
 *    swapped to its Lucide equivalent when the panel is served.
 * New code should reference "lucide-*" names directly.
 */
final class LucideIcons
{
    /** Heroicon case name without the Outlined/Solid prefix => Lucide name. */
    public const MAP = [
        'ShieldCheck' => 'shield-check', 'RectangleStack' => 'layers', 'Scale' => 'scale', 'ClipboardDocumentCheck' => 'clipboard-check',
        'CheckBadge' => 'badge-check', 'BuildingOffice2' => 'building-2', 'BuildingOffice' => 'building', 'ArrowsRightLeft' => 'arrow-left-right', 'Truck' => 'truck',
        'ShieldExclamation' => 'shield-alert', 'QueueList' => 'list-ordered', 'ListBullet' => 'list', 'ExclamationTriangle' => 'triangle-alert',
        'Clock' => 'clock', 'Briefcase' => 'briefcase', 'AdjustmentsHorizontal' => 'sliders-horizontal', 'Users' => 'users', 'UserGroup' => 'users-round',
        'TableCells' => 'table', 'Squares2x2' => 'layout-grid', 'Signal' => 'signal', 'NoSymbol' => 'ban', 'Language' => 'languages', 'InboxStack' => 'inbox',
        'DocumentText' => 'file-text', 'DocumentDuplicate' => 'files', 'DocumentCheck' => 'file-check', 'DevicePhoneMobile' => 'smartphone',
        'Calculator' => 'calculator', 'BuildingLibrary' => 'landmark', 'BookOpen' => 'book-open', 'Banknotes' => 'banknote', 'ArchiveBox' => 'archive',
        'UserCircle' => 'circle-user', 'Tag' => 'tag', 'Sparkles' => 'sparkles', 'ServerStack' => 'server', 'ReceiptPercent' => 'receipt', 'QrCode' => 'qr-code',
        'MapPin' => 'map-pin', 'LockClosed' => 'lock', 'Link' => 'link', 'Key' => 'key-round', 'Identification' => 'id-card', 'Hashtag' => 'hash',
        'Envelope' => 'mail', 'DocumentMagnifyingGlass' => 'file-search', 'DocumentCurrencyDollar' => 'receipt-text', 'DocumentChartBar' => 'file-chart-column',
        'ComputerDesktop' => 'monitor', 'Cog6Tooth' => 'settings', 'ClipboardDocumentList' => 'clipboard-list', 'CheckCircle' => 'circle-check',
        'ChatBubbleLeftRight' => 'messages-square', 'ChartPie' => 'chart-pie', 'ChartBar' => 'chart-column', 'CalendarDays' => 'calendar-days',
        'BuildingStorefront' => 'store', 'BellAlert' => 'bell-ring', 'ArrowUpTray' => 'upload', 'ArrowPath' => 'refresh-cw',
        'ArchiveBoxArrowDown' => 'archive-restore', 'Home' => 'house', 'User' => 'user', 'Eye' => 'eye', 'XCircle' => 'circle-x', 'ArrowDownTray' => 'download',
        'ArrowRightCircle' => 'circle-arrow-right', 'MagnifyingGlass' => 'search',
    ];

    public const ALIASES = [
        PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON => 'lucide-panel-left-close',
        PanelsIconAlias::SIDEBAR_EXPAND_BUTTON => 'lucide-panel-left-open',
        PanelsIconAlias::SIDEBAR_GROUP_COLLAPSE_BUTTON => 'lucide-chevron-up',
        PanelsIconAlias::TOPBAR_OPEN_SIDEBAR_BUTTON => 'lucide-menu',
        PanelsIconAlias::TOPBAR_CLOSE_SIDEBAR_BUTTON => 'lucide-x',
        PanelsIconAlias::TOPBAR_GROUP_TOGGLE_BUTTON => 'lucide-chevron-down',
        PanelsIconAlias::USER_MENU_PROFILE_ITEM => 'lucide-circle-user',
        PanelsIconAlias::USER_MENU_LOGOUT_BUTTON => 'lucide-log-out',
        PanelsIconAlias::GLOBAL_SEARCH_FIELD => 'lucide-search',
        PanelsIconAlias::PAGES_DASHBOARD_NAVIGATION_ITEM => 'lucide-house',
        PanelsIconAlias::RESOURCES_PAGES_VIEW_RECORD_NAVIGATION_ITEM => 'lucide-eye',
        PanelsIconAlias::RESOURCES_PAGES_EDIT_RECORD_NAVIGATION_ITEM => 'lucide-pencil',
        PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON => 'lucide-log-out',
    ];

    public static function lucideFor(string|BackedEnum|null $icon): string|BackedEnum|null
    {
        if (! $icon instanceof Heroicon) {
            return $icon;
        }
        $name = preg_replace('/^(Outlined|Solid|Mini|Micro)/', '', $icon->name);

        return isset(self::MAP[$name]) ? 'lucide-'.self::MAP[$name] : $icon;
    }

    /** Filament::serving hook: register aliases and swap the current panel's navigation icons. */
    public static function apply(): void
    {
        FilamentIcon::register(self::ALIASES);
        $panel = Filament::getCurrentPanel();
        if ($panel === null) {
            return;
        }
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            if (! property_exists($class, 'navigationIcon')) {
                continue;
            }
            $prop = new ReflectionProperty($class, 'navigationIcon');
            $current = $prop->getValue();
            $swapped = self::lucideFor($current);
            if ($swapped !== $current) {
                $prop->setValue(null, $swapped);
            }
        }
    }
}
