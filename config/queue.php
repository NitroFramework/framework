<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    |
    | The connection name used when Job::dispatch() doesn't specify one.
    | Match the connection key under 'connections' below.
    |
    */
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Each connection picks a driver and provides driver-specific options.
    | A "connection" is a storage backend; named queues ('default', 'mail',
    | 'reports') live INSIDE a connection. One database connection can hold
    | many queues — workers pick which queues to serve via --queue=...
    |
    | Drivers:
    |   sync      — Run jobs inline on dispatch. Dev/tests.
    |   array     — In-memory. Tests that exercise queue semantics.
    |   database  — Real queue, stored in the SQL `jobs` table. Production.
    |
    */
    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver'      => 'database',
            'table'       => 'jobs',
            // Seconds before a reserved job is considered orphaned and
            // becomes eligible for another worker to pick up. Set to
            // a value LONGER than the slowest expected job runtime.
            'retry_after' => 90,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Job Storage
    |--------------------------------------------------------------------------
    */
    'failed' => [
        'table' => 'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Defaults
    |--------------------------------------------------------------------------
    |
    | Defaults applied when queue:work is run without the corresponding flag.
    | Individual flags on the command line override these.
    |
    */
    'worker' => [
        'sleep'      => 1,       // Seconds to wait when the queue is empty.
        'tries'      => null,    // null = honor each job's own $tries.
        'max_jobs'   => 0,       // 0 = unlimited (process until signal).
        'max_time'   => 0,       // 0 = unlimited (process until signal).
        'max_memory' => 128,     // MB — exit and let supervisor respawn.
    ],

];
