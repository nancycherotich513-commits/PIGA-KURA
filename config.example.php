<?php
/**
 * Copy this file to config.php and adjust the folder path and database values.
 */
return [
    'app_name' => 'VoteHub',
    'base_url' => '/PIGA-KURA',
    'timezone' => 'Africa/Nairobi',
    'upload_max_bytes' => 2 * 1024 * 1024,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'voting_system',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];
