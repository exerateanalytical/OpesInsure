<?php
declare(strict_types=1);
it('keeps risk and quote web queries tenant scoped',function(){expect(file_get_contents(base_path('app/Filament/Admin/Resources/RiskAssets/RiskAssetResource.php')))->toContain("where('tenant_id'");expect(file_get_contents(base_path('app/Filament/Admin/Resources/Quotes/QuoteResource.php')))->toContain("where('tenant_id'");});
