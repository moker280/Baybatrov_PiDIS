<?php

declare(strict_types=1);

// Роутер встроенного PHP-сервера. Используется расширением "PHP Server"
// (см. .vscode/settings.json) и командой: php -S localhost:3000 router.php
// Корень сервера — весь проект, поэтому роутер:
//   * пускает в веб только файлы внутри public/;
//   * закрывает config.php, src/, sql/, logs/, scripts/, captcha/, docs/ (403);
//   * позволяет работать адресам и /public/..., и /... без редиректов (POST сохраняется).

$publicDir = realpath(__DIR__ . '/public');
if ($publicDir === false) {
    http_response_code(500);
    exit('Каталог public/ не найден.');
}

$uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$insidePublic = static fn (string $path): bool => str_starts_with($path, $publicDir . DIRECTORY_SEPARATOR);

// Ищет файл внутри public/. Поддерживает PATH_INFO: /api.php/report/summary -> api.php + /report/summary.
$resolve = static function (string $rel) use ($publicDir, $insidePublic): ?array {
    if ($rel === '' || $rel === '/') {
        return ['file' => $publicDir . DIRECTORY_SEPARATOR . 'index.php', 'pathInfo' => null];
    }
    $full = realpath($publicDir . $rel);
    if ($full !== false && $insidePublic($full) && is_file($full)) {
        return ['file' => $full, 'pathInfo' => null];
    }
    if (preg_match('#^(.+?\.php)(/.*)$#i', $rel, $m) === 1) {
        $full = realpath($publicDir . $m[1]);
        if ($full !== false && $insidePublic($full) && is_file($full)) {
            return ['file' => $full, 'pathInfo' => $m[2]];
        }
    }
    return null;
};

// 1) Адреса с префиксом /public/: файл физически существует — отдаём его встроенному
//    серверу (return false): корректные MIME, PATH_INFO и заголовки.
if ($uriPath === '/public' || $uriPath === '/public/') {
    require $publicDir . DIRECTORY_SEPARATOR . 'index.php';
    return true;
}
if (str_starts_with($uriPath, '/public/')) {
    if ($resolve(substr($uriPath, 7)) !== null) {
        return false;
    }
    http_response_code(404);
    exit('Страница не найдена.');
}

// 2) Корневые адреса сайта (/login.php, /assets/style.css, /admin/..., /api.php/...):
//    отдаём те же файлы из public/. Без редиректов — данные POST не теряются.
$match = $resolve($uriPath);
if ($match !== null) {
    if ($match['pathInfo'] !== null) {
        $_SERVER['PATH_INFO'] = $match['pathInfo'];
    }
    if (strtolower(pathinfo($match['file'], PATHINFO_EXTENSION)) === 'php') {
        require $match['file'];
        return true;
    }
    $mime = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
    ];
    $ext = strtolower(pathinfo($match['file'], PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($match['file']));
    header('X-Content-Type-Options: nosniff');
    readfile($match['file']);
    return true;
}

// 3) Всё остальное (config.php, sql/, logs/, src/, scripts/, captcha/, docs/, скрытые файлы) запрещено.
http_response_code(403);
exit('Доступ запрещён.');