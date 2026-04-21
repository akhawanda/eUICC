<?php

// Snippets that bootstrap.sh merges into the Laravel 12 bootstrap/app.php.
// Keep this file as reference — not executed directly.

return [
    'routing' => [
        'api'   => __DIR__.'/../routes/api.php',
        'apiPrefix' => 'api',
    ],
    'middleware_aliases' => [
        'sim.token' => \App\Http\Middleware\VerifySimulatorToken::class,
    ],
];
