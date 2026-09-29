<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms;

/**
 * GSM 03.38 length handling. GSM-7: 160 chars in one SMS, 153 per part when concatenated; the extension
 * characters (^{}\[~]|€ and form feed) cost two. Anything outside the GSM-7 alphabet switches the whole
 * message to UCS-2: 70 chars in one SMS, 67 per part. Characters outside the BMP (emoji) count as two UCS-2 units.
 */
final class SmsEncoding
{
    private const BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const EXTENSION = "^{}\\[~]|€\f";

    /** @return array{encoding: string, length: int, segments: int, per_segment: int} */
    public static function analyse(string $message): array
    {
        $chars = mb_str_split($message);
        $gsm = true;
        $units = 0;

        foreach ($chars as $c) {
            if (mb_strpos(self::BASIC, $c) !== false) {
                $units++;
            } elseif (mb_strpos(self::EXTENSION, $c) !== false) {
                $units += 2;
            } else {
                $gsm = false;
                break;
            }
        }

        if (! $gsm) {
            $units = 0;
            foreach ($chars as $c) {
                $units += mb_ord($c) > 0xFFFF ? 2 : 1;
            }
            $single = 70;
            $multi = 67;
        } else {
            $single = 160;
            $multi = 153;
        }

        $segments = $units === 0 ? 1 : ($units <= $single ? 1 : (int) ceil($units / $multi));

        return ['encoding' => $gsm ? 'GSM7' : 'UCS2', 'length' => $units, 'segments' => $segments, 'per_segment' => $segments > 1 ? $multi : $single];
    }
}
