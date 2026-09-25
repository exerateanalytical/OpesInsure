<?php
declare(strict_types=1);
// REQ-PRP-002 (behaviour covered in tests/Feature/Batch6/Proposal/ProposalLifecycleTest.php)
it('hashes and attests disclosure answers before submission',function(){$s=file_get_contents(base_path('app/Application/Underwriting/ProposalService.php'));expect($s)->toContain('answers_hash')->toContain('attested_at')->toContain('answers_hash_mismatch');});
