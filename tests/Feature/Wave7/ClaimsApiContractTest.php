<?php
it('publishes wave seven routes separately for conflict free integration',function(){$r=file_get_contents(base_path('routes/wave7.php'));foreach(['claims/fnol','/assign','/reserves','/decisions','/evidence','/disputes','/recoveries','/carrier-messages','/payments']as$path)expect($r)->toContain($path);});
it('does not hard code statutory limits',function(){$files=glob(app_path('Application/Claims/*.php'));$source=implode('',array_map('file_get_contents',$files));expect(strtolower($source))->not->toContain('cima')->not->toMatch('/statutory.{0,30}\d+/i');});
