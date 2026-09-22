<?php
declare(strict_types=1);
it('documents maker checker hash and overlap controls',function(){expect(file_get_contents(base_path('app/Application/Rating/TariffGovernanceService.php')))->toContain('tariff_hash_mismatch')->toContain('maker_checker')->toContain('tariff_overlap');});
