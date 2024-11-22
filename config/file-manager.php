<?php

// write all the Models names according to their path names.

return [
    'cdn' => env('CDN_URL', env('AWS_URL', env('APP_URL'))),

    'model' => [
        //Write like this others according to your project
        'User' => 'users',
    ],
];
