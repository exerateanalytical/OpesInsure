<?php

return [
    /*
     * Owner decision 20 (2026-09-25): the ODbL global vehicle dataset (github.com/gor3a/vehicle-makes-models) is NOT
     * imported; the curated vehicle master is preferred. The importer is hard-disabled: a real import is refused,
     * even with --accept-license, unless the OWNER sets this flag in the server .env. --dry-run (writes nothing) stays available.
     */
    'global_dataset_import_enabled' => (bool) env('VEHICLE_GLOBAL_DATASET_IMPORT_ENABLED', false),
];
