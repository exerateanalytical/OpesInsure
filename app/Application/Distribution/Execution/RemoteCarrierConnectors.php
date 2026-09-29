<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

use App\Application\Integrations\Activa\ActivaRemoteConnector;

/** REQ-AOM-002 — the carrier API connectors known to the platform (one per carrier API family). */
final class RemoteCarrierConnectors
{
    /** @var list<class-string<RemoteCarrierConnector>> */
    public const CONNECTORS = [ActivaRemoteConnector::class];

    public function for(string $carrierId): ?RemoteCarrierConnector
    {
        foreach (self::CONNECTORS as $class) {
            $connector = app($class);
            if ($connector->handles($carrierId)) {
                return $connector;
            }
        }

        return null;
    }
}
