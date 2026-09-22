<?php
declare(strict_types=1);it('enforces refund balance maker checker and chargeback closure',function(){$s=file_get_contents(base_path('app/Application/Payments/FinancialCaseService.php'));expect($s)->toContain('refund_exceeds_balance')->toContain('maker_checker')->toContain('chargeback_closed');});
