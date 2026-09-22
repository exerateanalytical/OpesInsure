<?php
declare(strict_types=1);
namespace App\Application\Shared;
final class CanonicalJson{public function encode(array$data):string{$normalized=$this->normalize($data);return json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}public function hash(array$data):string{return hash('sha256',$this->encode($data));}private function normalize(mixed$value):mixed{if(!is_array($value))return$value;if(array_is_list($value))return array_map(fn($v)=>$this->normalize($v),$value);ksort($value);return array_map(fn($v)=>$this->normalize($v),$value);}}
