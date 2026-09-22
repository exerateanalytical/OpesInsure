<?php
declare(strict_types=1);it('blocks decisions while referral tasks remain open',function(){$s=file_get_contents(base_path('app/Application/Underwriting/UnderwritingService.php'));expect($s)->toContain("referrals()->where('status','OPEN')->exists()")->toContain('open_referrals');});
