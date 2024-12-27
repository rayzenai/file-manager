<?php

return [
    'cdn' => env('CDN_URL', env('AWS_URL', env('APP_URL'))),

    'max-upload-height' => '5120', // in pixels

    'max-upload-width' => '5120', // in pixels

    'max-upload-size' => '8192', // in KB

    // The ModelName => path syntax is used to store the uploaded files in the respective folder
    'model' => [
        'User' => 'users',
        'Mockup' => 'mockups'
    ],
];