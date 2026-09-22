<?php
use App\Application\Identity\TotpService;
test('totp follows the rfc 6238 sha1 test vector truncated to six digits',function(){expect((new TotpService)->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','287082',59,0))->toBeTrue();});
test('totp rejects an incorrect code',function(){expect((new TotpService)->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','000000',59,0))->toBeFalse();});
