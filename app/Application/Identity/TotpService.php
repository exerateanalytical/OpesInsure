<?php
declare(strict_types=1);
namespace App\Application\Identity;
final class TotpService
{
    private const ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public function secret():string{$bytes=random_bytes(20);$bits='';foreach(str_split($bytes)as$b)$bits.=str_pad(decbin(ord($b)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5)as$chunk)if(strlen($chunk)===5)$out.=self::ALPHABET[bindec($chunk)];return$out;}
    public function verify(string$secret,string$code,?int$time=null,int$window=1):bool{$counter=intdiv($time??time(),30);for($offset=-$window;$offset<=$window;$offset++)if(hash_equals($this->code($secret,$counter+$offset),$code))return true;return false;}
    private function code(string$secret,int$counter):string{$key=$this->decode($secret);$bin=pack('N2',($counter>>32)&0xffffffff,$counter&0xffffffff);$hash=hash_hmac('sha1',$bin,$key,true);$offset=ord($hash[19])&15;$value=((ord($hash[$offset])&127)<<24)|((ord($hash[$offset+1])&255)<<16)|((ord($hash[$offset+2])&255)<<8)|(ord($hash[$offset+3])&255);return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);}
    private function decode(string$input):string{$input=strtoupper(preg_replace('/[^A-Z2-7]/','',$input));$bits='';foreach(str_split($input)as$c)$bits.=str_pad(decbin(strpos(self::ALPHABET,$c)),5,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,8)as$b)if(strlen($b)===8)$out.=chr(bindec($b));return$out;}
}
