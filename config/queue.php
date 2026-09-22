<?php
return ['default' => env('QUEUE_CONNECTION', 'redis'), 'connections' => ['redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => env('REDIS_QUEUE', 'default'), 'retry_after' => 120, 'block_for' => 5, 'after_commit' => true]], 'failed' => ['driver' => 'database-uuids', 'database' => 'pgsql', 'table' => 'failed_jobs']];
