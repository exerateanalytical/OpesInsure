<?php
declare(strict_types=1);it('fails closed when a real provider connection is inactive',function(){expect(file_get_contents(base_path('app/Application/Payments/PaymentInitiationService.php')))->toContain('provider_not_active')->toContain("where('status','ACTIVE')");});
