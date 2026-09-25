<?php

return [
    'tariff_not_yet_effective' => 'This tariff version is not effective yet; schedule it instead.',
    'tariff_until_before_from' => 'The end date cannot be before the start date.',
    'discount_invalid' => 'Each discount needs a code, a known type and 0–10000 basis points.',
    'method_invalid' => 'Unknown rating method or missing method parameters.',
    'rounding_invalid' => 'Rounding mode must be HALF_UP, UP or DOWN.',
    'allocation_invalid' => 'Branch allocation shares must add up to 10000 basis points.',
    'owner_source_required' => 'Owner-confirmed rates need a source reference.',
    'charge_overlap' => 'Another approved version of this charge table overlaps these dates.',
    'charges_required' => 'At least one charge is required.',
    'charge_invalid' => 'Each charge needs a catalogued code of the right kind, a basis and a valid rate or amount.',
];
