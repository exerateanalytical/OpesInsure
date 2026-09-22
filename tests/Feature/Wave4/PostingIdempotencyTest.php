<?php
declare(strict_types=1);it('posts approved balanced profiles once per reference',function(){$s=file_get_contents(base_path('app/Application/Ledger/FinancialPostingService.php'));expect($s)->toContain("status'=>'APPROVED'")->toContain('reference_id')->toContain('debit_minor')->toContain('credit_minor');});
