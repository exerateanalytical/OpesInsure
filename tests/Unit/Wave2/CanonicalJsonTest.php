<?php
declare(strict_types=1);
use App\Application\Shared\CanonicalJson;
it('hashes equivalent rule objects deterministically',function(){$json=new CanonicalJson;expect($json->hash(['b'=>2,'a'=>1]))->toBe($json->hash(['a'=>1,'b'=>2]));});
