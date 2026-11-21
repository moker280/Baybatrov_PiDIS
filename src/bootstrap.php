<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
define('APP_CONFIG', require APP_ROOT . '/config.php');
define('APP_LOG', APP_ROOT . '/logs/app.log');

date_default_timezone_set(APP_CONFIG['app']['timezone']);
error_reporting(E_ALL);

require __DIR__ . '/functions.php';
require __DIR__ . '/db.php';
require __DIR__ . '/audit.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/entities.php';
require __DIR__ . '/entity_ops.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/pdf.php';

if (!is_dir(APP_ROOT . '/logs')) {
    mkdir(APP_ROOT . '/logs', 0770, true);
}

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        app_log_write('Deprecated: ' . $msg . ' @ ' . $file . ':' . $line);
        return true;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    app_log_write($e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
        . "\n" . $e->getTraceAsString());
    $dbError = $e instanceof DbUnavailableException;
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, ($dbError ? $e->getMessage() : 'Внутренняя ошибка. Подробности: logs/app.log') . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code($dbError ? 503 : 500);
    }
    $text = $dbError
        ? 'Нет соединения с базой данных. Проверьте параметры в config.php и разрешён ли доступ '
            . 'с этого компьютера к удалённому серверу MariaDB. Технические подробности — в журнале сервера.'
        : 'Внутренняя ошибка сервера. Подробности записаны в журнал logs/app.log.';
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Ошибка — АИС «СРА»</title><link rel="stylesheet" href="/assets/style.css"></head>'
        . '<body class="area-guest"><main class="container"><div class="card error-page">'
        . '<h1>Ошибка</h1><p>' . e($text) . '</p>'
        . '<p><a class="btn" href="/login.php">На страницу входа</a></p>'
        . '</div></main></body></html>';
    exit;
});

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('SRA_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // Локальный запуск по HTTP работает; при HTTPS cookie автоматически становится Secure.
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();

    $idle = (int) APP_CONFIG['app']['idle_seconds'];
    if (
        isset($_SESSION['last_activity'])
        && time() - (int) $_SESSION['last_activity'] > $idle
        && (isset($_SESSION['user_id']) || isset($_SESSION['pending_user_id']))
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash_add('info', 'Сессия завершена из-за неактивности. Войдите снова.');
        $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($self, ['login.php', 'register.php'], true)) {
            redirect('/login.php');
        }
    }
    $_SESSION['last_activity'] = time();
}