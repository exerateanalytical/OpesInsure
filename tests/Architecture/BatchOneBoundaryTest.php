<?php
test('domain layer does not depend on HTTP or infrastructure', function () {
    $files=glob(__DIR__.'/../../app/Domain/*/*.php');
    foreach($files as $file){$source=file_get_contents($file); expect($source)->not->toContain('Illuminate\\Http')->not->toContain('App\\Interfaces')->not->toContain('App\\Infrastructure');}
});
