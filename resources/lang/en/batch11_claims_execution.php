<?php

declare(strict_types=1);

return [
    'no_carrier_submission' => 'Claims in mode :mode are handled on the platform and are not submitted to the carrier.',
    'channel_not_allowed' => 'Claims in mode :mode do not accept carrier messages over :channel.',
    'signature_invalid' => 'The carrier message signature is missing, expired or invalid.',
    'entry_not_pending' => 'This carrier entry has already been reviewed.',
    'maker_checker' => 'A carrier entry must be approved by someone other than the person who entered it.',
];
