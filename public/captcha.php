<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post_str('action') === 'refresh') {
        captcha_generate();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['tokens' => captcha_tokens()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    exit('Неизвестное действие.');
}

$token = get_str('t');
$path = captcha_image_path($token);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Фрагмент недоступен: задание капчи устарело.');
}
header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;