<?php

declare(strict_types=1);

/*
 * La caisse tourne comme une application à part, servie depuis une autre
 * origine que l'API. Les origines autorisées viennent de l'environnement :
 * un joker en production ouvrirait l'API à n'importe quelle page web.
 */
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type',
        'X-Organization', 'X-Request-Id', 'Idempotency-Key',
    ],

    // Le client lit le numéro de requête pour le citer au support, et le
    // marqueur de rejeu pour savoir qu'il ne doit pas réencaisser.
    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replay'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
