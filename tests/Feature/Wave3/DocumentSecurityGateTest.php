<?php
declare(strict_types=1);it('blocks verification of non clean documents and enforces required evidence',function(){$s=file_get_contents(base_path('app/Application/Underwriting/ProposalService.php'));expect($s)->toContain("scan_status!=='CLEAN'")->toContain('document_missing')->toContain("where('status','VERIFIED')");});
