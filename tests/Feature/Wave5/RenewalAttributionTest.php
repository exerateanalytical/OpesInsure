<?php
declare(strict_types=1);it('preserves original customer attribution on renewal quotes',function(){$s=file_get_contents(base_path('app/Application/Policies/RenewalService.php'));expect($s)->toContain('attribution_id'."'".' => $oldQuote->attribution_id')->toContain('previous_policy_id');});
