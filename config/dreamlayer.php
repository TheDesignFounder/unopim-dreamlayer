<?php

return [
    'api_url'              => env('DREAMLAYER_API_URL', 'https://api.dreamlayer.io/v1'),
    'platform_url'         => env('DREAMLAYER_PLATFORM_URL', 'https://platform.dreamlayer.io'),
    'allow_local_http'     => env('DREAMLAYER_ALLOW_LOCAL_HTTP', false),
    'connections_enabled'  => env('DREAMLAYER_CONNECTIONS_ENABLED', false),
    'persist_chat_history' => env('DREAMLAYER_PERSIST_CHAT_HISTORY', true),
    'queue'                => 'dreamlayer',
    'batch_concurrency'    => 2,
    'max_image_bytes'      => 20 * 1024 * 1024,
    'billing_url'          => rtrim(env('DREAMLAYER_PLATFORM_URL', 'https://platform.dreamlayer.io'), '/').'/console/billing',
];
