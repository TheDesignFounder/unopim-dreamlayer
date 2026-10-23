<?php

return [
    'api_url' => env('DREAMLAYER_API_URL', 'https://api.dreamlayer.io/v1'),
    'platform_url' => env('DREAMLAYER_PLATFORM_URL', 'https://platform.dreamlayer.io'),
    'allow_local_http' => env('DREAMLAYER_ALLOW_LOCAL_HTTP', false),
    'connections_enabled' => env('DREAMLAYER_CONNECTIONS_ENABLED', false),
    'queue_connection' => env('DREAMLAYER_QUEUE_CONNECTION'),
    'queue' => env('DREAMLAYER_QUEUE', 'dreamlayer'),
    'workflow_timeout' => max(120, (int) env('DREAMLAYER_WORKFLOW_TIMEOUT', 1800)),
    'poll_interval' => 5,
    'batch_concurrency' => 2,
    'max_image_bytes' => 20 * 1024 * 1024,
    'billing_url' => rtrim(env('DREAMLAYER_PLATFORM_URL', 'https://platform.dreamlayer.io'), '/').'/console/billing',
];
