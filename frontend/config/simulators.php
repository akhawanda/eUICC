<?php

return [
    'euicc' => [
        'url' => env('EUICC_SIM_URL', 'http://127.0.0.1:8100'),
        'timeout' => 30,
    ],
    'ipa' => [
        'url' => env('IPA_SIM_URL', 'http://127.0.0.1:8101'),
        'timeout' => 60,
    ],
    'seed_token' => env('SIM_SEED_TOKEN'),
];
