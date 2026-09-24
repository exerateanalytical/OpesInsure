<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use Illuminate\Http\Client\Response;

/** Pulls a message id out of an ETECH response, whatever shape it has. */
final class EtechReference
{
    public static function from(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            foreach (['id', 'message_id', 'messageId', 'data.id', 'data.message_id', 'messages.0.id'] as $key) {
                $value = data_get($json, $key);
                if (is_scalar($value) && (string) $value !== '') {
                    return mb_substr((string) $value, 0, 190);
                }
            }

            return '';
        }

        return mb_substr(trim($response->body()), 0, 190);
    }
}
