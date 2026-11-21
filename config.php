<?php

declare(strict_types=1);

// Настройки подключения — как в рабочем образце: сервер 134.90.167.42, порт MariaDB 10306.
// Пароль, ключ шифрования и API-токен подставьте свои; в репозиторий отправляйте версию с заглушками.
return [
    'db' => [
        'host' => '134.90.167.42',      // только IP: без http:// и без :порта
        'port' => '10306',              // порт MariaDB (10307 — это phpMyAdmin, не сюда)
        'name' => 'project_Baybatrov',  // имя вашей БД, без пробелов
        'user' => 'Baybatrov',
        'pass' => 'BG!(zXj1n@iblWpi',
        'charset' => 'utf8mb4',
        // TLS к удалённой СУБД: на учебном стенде не используется, как и в образце.
        'tls' => [
            'enabled' => false,
            'ca' => '',
            'verify' => false,
        ],
    ],
    'app' => [
        'timezone' => 'Europe/Moscow',
        'idle_seconds' => 1800,
        'demo_mode' => true, // «Демо-код» на экране — учебная имитация второго фактора
        'captcha_order' => [1, 2, 3, 4],
    ],
    'security' => [
        'otp_ttl' => 300,
        'max_attempts' => 3,
        'lock_seconds' => 3600,
        'password_days' => 90,
        // 64 hex-символа. Сгенерируйте: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'data_key' => '0000000000000000000000000000000000000000000000000000000000000000',
        'api_token' => 'REPLACE_WITH_RANDOM_API_TOKEN',
    ],
    'pdf' => [
        'font' => '',
        'fonts' => [
            'fonts/DejaVuSans.ttf',
            'fonts/LiberationSans-Regular.ttf',
            'fonts/arial.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            'C:/Windows/Fonts/arial.ttf',
        ],
    ],
];