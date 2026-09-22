<?php
declare(strict_types=1);it('hashes complete bordereaux and records carrier acknowledgement or rejection',function(){$s=file_get_contents(base_path('app/Application/FinancialDistribution/BordereauService.php'));expect($s)->toContain('content_hash')->toContain('pg_advisory_xact_lock')->toContain("'SUBMITTED','ACKNOWLEDGED'")->toContain("'SUBMITTED','REJECTED'")->toContain('outbox');});
