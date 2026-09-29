<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Filament\Shared\Pages\GlobalSearchPage;

/** SHR-001 / SHR-002 in the admin panel (auto-discovered); the portals register GlobalSearchPage via PortalPanelFactory. */
final class GlobalSearch extends GlobalSearchPage {}
