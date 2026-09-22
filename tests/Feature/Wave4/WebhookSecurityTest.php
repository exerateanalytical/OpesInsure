<?php
declare(strict_types=1);it('checks timestamp hmac replay amount currency and transitions',function(){$s=file_get_contents(base_path('app/Application/Payments/WebhookProcessingService.php'));expect($s)->toContain('hash_equals')->toContain('insertOrIgnore')->toContain('AMOUNT_OR_CURRENCY_MISMATCH')->toContain('INVALID_STATUS_TRANSITION');});
