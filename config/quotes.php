<?php

return [
    // REQ-QUO-002: fallback validity when the product version sets no quote_validity_days.
    'default_validity_days' => (int) env('QUOTE_DEFAULT_VALIDITY_DAYS', 7),

    // REQ-DST-001: partner (agent/broker) quotes always require sellability. Direct B2C quotes also require a live
    // marketplace publication on the channel only when this is on. Off until tenants publish their products (live
    // tenants have none today; turning it on would stop every direct quote). Owner decision pending.
    'enforce_direct_publication' => (bool) env('QUOTE_ENFORCE_DIRECT_PUBLICATION', false),
];
