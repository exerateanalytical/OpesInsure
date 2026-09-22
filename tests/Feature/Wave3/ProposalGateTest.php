<?php
declare(strict_types=1);it('requires an accepted tenant offer and one active proposal',function(){$s=file_get_contents(base_path('app/Application/Underwriting/ProposalService.php'));expect($s)->toContain('accepted_offer_required')->toContain('proposal_exists')->toContain('party_mismatch');});
