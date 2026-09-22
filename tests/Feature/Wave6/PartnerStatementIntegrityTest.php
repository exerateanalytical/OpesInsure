<?php
declare(strict_types=1);it('snapshots statement items under locks and publishes only after approval',function(){$s=file_get_contents(base_path('app/Application/FinancialDistribution/PartnerStatementService.php'));expect($s)->toContain('lockForUpdate')->toContain('content_hash')->toContain("status'=>'APPROVED'")->toContain("status'=>'PUBLISHED'")->toContain('idempotency_key');});
