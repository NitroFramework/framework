<?php

return [
    'default' => 'sync',
    'connections' => [
        'sync' => ['driver' => 'sync'],
    ],
    'failed' => ['driver' => 'null'],
];
