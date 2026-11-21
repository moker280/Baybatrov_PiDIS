<?php

declare(strict_types=1);

// Все значения — фиктивные заглушки для локального учебного запуска. Замените своими.
return [
    'db' => [
        'host' => 'DB_HOST', // адрес удалённого сервера MariaDB (НЕ адрес phpMyAdmin)
        'port' => 'DB_PORT', // обычно 3306
        'name' => 'DB_NAME', // имя уже созданной пустой БД
        'user' => 'DB_USER',
        'pass' => 'DB_PASS',
        'charset' => 'utf8mb4',
        // TLS для соединения с удалённой СУБД. enabled=true включает шифрование канала;
        // ca — путь к PEM-файлу корневого сертификата сервера; verify — проверять сертификат.
        'tls' => [
            'enabled' => false,
            'ca' => '',
            'verify' => false,
        ],
    ],
    'app' => [
        'timezone' => 'Europe/Moscow',
        'idle_seconds' => 1800,
        // demo_mode=true: «Демо-код» второго фактора выводится на экране (учебная имитация 2FA).
        'demo_mode' => true,
    ],
    'security' => [
        'otp_ttl' => 300,        // срок действия одноразового кода, секунд
        'max_attempts' => 3,     // попыток до блокировки (пароль и код считаются раздельно)
        'lock_seconds' => 3600,  // длительность блокировки
        'password_days' => 90,   // принудительная смена пароля
        // Ключ AES-256-GCM (32 байта = 64 hex). Сгенерируйте свой:
        // php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'data_key' => '0000000000000000000000000000000000000000000000000000000000000000',
        // Токен REST API для внешнего C++-приложения (заголовок X-API-Token).
        'api_token' => 'REPLACE_WITH_RANDOM_API_TOKEN',
    ],
];