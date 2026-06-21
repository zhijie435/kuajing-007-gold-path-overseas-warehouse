<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'overseas_warehouse',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Overseas Warehouse Fulfillment System',
        'timezone' => 'Asia/Shanghai',
        'debug' => true,
    ],
    'warehouse' => [
        'default_shipping_days' => 5,
        'routing_strategy' => 'nearest',
    ],
    'callback' => [
        'token' => 'wh_callback_token_2024',
    ],
    'security' => [
        'require_api_auth' => false,
        'permission' => [
            'enable' => true,
        ],
        'audit' => [
            'enable' => true,
            'log_request_body' => true,
            'log_response_body' => true,
            'log_old_new_data' => true,
        ],
        'ip' => [
            'trust_x_forwarded_for' => true,
            'x_forwarded_for_index' => 0,
        ],
    ],
];
