<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Debug API Key
    |--------------------------------------------------------------------------
    |
    | Gates the read-only debug API (routes/api.php). This is a production
    | debugging backdoor by design — keep it secret, rotate it by changing
    | DEBUG_API_KEY and redeploying if it's ever exposed.
    |
    */
    'key' => env('DEBUG_API_KEY'),
];
