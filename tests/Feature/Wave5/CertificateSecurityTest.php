<?php
declare(strict_types=1);it('hashes verification tokens and tracks sticker custody',function(){$s=file_get_contents(base_path('app/Application/Certificates/CertificateService.php'));expect($s)->toContain('hash('."'".'sha256'."'".',$token)')->toContain('sticker_custody_events')->toContain('request_fingerprint_hash');});
