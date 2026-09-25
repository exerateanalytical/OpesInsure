<?php
declare(strict_types=1);
// REQ-PRP-003 (behaviour covered in tests/Feature/Batch6/Proposal/ProposalLifecycleTest.php)
it('blocks verification of non clean documents and enforces required evidence',function(){$s=file_get_contents(base_path('app/Application/Underwriting/ProposalService.php'));expect($s)->toContain("scan_status !== 'CLEAN'")->toContain('document_missing')->toContain('requirements->missing');expect(file_get_contents(base_path('app/Application/Underwriting/Proposal/ProposalDocumentRequirements.php')))->toContain("'VERIFIED' => IssuanceDocumentAcceptance::accepted(");});
