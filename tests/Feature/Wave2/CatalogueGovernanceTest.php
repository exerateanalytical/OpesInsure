<?php
declare(strict_types=1);
it('documents product publication governance',function(){expect(file_get_contents(base_path('app/Application/Catalogue/CatalogueService.php')))->toContain('mandatory_coverages')->toContain('maker_checker')->toContain('approved_tariff_required');});
