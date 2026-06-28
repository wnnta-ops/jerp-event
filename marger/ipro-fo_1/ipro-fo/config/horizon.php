<?php

declare(strict_types=1);

return [
    'domain'       => env('HORIZON_DOMAIN'),
    'path'         => env('HORIZON_PATH', 'horizon'),
    'use'          => 'default',
    'prefix'       => env('HORIZON_PREFIX', 'horizon:'),
    'middleware'   => ['web', 'auth'],
    'waits'        => ['redis:default' => 60],
    'trim'         => [
        'recent'         => 60,
        'pending'        => 60,
        'completed'      => 60,
        'recent_failed'  => 10080,
        'failed'         => 10080,
        'monitored'      => 10080,
    ],
    'silenced'      => [],
    'metrics'       => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit'  => 256,

    'defaults' => [
        'supervisor-realtime' => [
            'connection'         => 'redis',
            'queue'              => ['realtime'],
            'balance'            => 'auto',
            'autoScalingStrategy'=> 'time',
            'maxProcesses'       => 10,
            'minProcesses'       => 2,
            'tries'              => 3,
            'timeout'            => 30,
            'nice'               => 0,
        ],
        'supervisor-default' => [
            'connection'         => 'redis',
            'queue'              => ['default', 'mail'],
            'balance'            => 'auto',
            'maxProcesses'       => 5,
            'tries'              => 3,
            'timeout'            => 120,
        ],
        'supervisor-reports' => [
            'connection'         => 'redis',
            'queue'              => ['reports'],
            'balance'            => 'simple',
            'maxProcesses'       => 2,
            'tries'              => 1,
            'timeout'            => 600,
        ],
        'supervisor-long' => [
            'connection'         => 'redis',
            'queue'              => ['long'],
            'balance'            => 'simple',
            'maxProcesses'       => 1,
            'tries'              => 1,
            'timeout'            => 3600,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-realtime' => ['maxProcesses' => 10, 'balanceMaxShift' => 1, 'balanceCooldown' => 3],
            'supervisor-default'  => ['maxProcesses' => 10],
            'supervisor-reports'  => ['maxProcesses' => 3],
            'supervisor-long'     => ['maxProcesses' => 2],
        ],
        'local' => [
            'supervisor-realtime' => ['maxProcesses' => 3],
            'supervisor-default'  => ['maxProcesses' => 3],
            'supervisor-reports'  => ['maxProcesses' => 1],
            'supervisor-long'     => ['maxProcesses' => 1],
        ],
    ],
];
