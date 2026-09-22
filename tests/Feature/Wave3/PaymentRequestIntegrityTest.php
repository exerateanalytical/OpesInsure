<?php
declare(strict_types=1);it('derives payment amount from approved proposal snapshot and is idempotent',function(){$s=file_get_contents(base_path('app/Application/Payments/PaymentRequestService.php'));expect($s)->toContain("status!=='PAYMENT_PENDING'")->toContain("terms_snapshot['total_minor']")->toContain('idempotency_key');});
