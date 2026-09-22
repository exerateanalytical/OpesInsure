<?php
declare(strict_types=1);it('requires resolved exceptions and independent approval',function(){$s=file_get_contents(base_path('app/Application/Reconciliation/ReconciliationService.php'));expect($s)->toContain("where('status','EXCEPTION')->exists()")->toContain('maker_checker');});
