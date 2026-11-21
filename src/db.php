<?php

declare(strict_types=1);

final class DbUnavailableException extends RuntimeException
{
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = APP_CONFIG['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
    if (!empty($c['tls']['enabled'])) {
        if (!empty($c['tls']['ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $c['tls']['ca'];
        }
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($c['tls']['verify'] ?? false);
    }
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
        // Синхронизируем часовой пояс сессии БД с PHP: NOW() в SQL совпадает со временем PHP.
        $pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");
    } catch (PDOException $e) {
        app_log_write('DB connect: ' . $e->getMessage());
        throw new DbUnavailableException('Нет соединения с базой данных.', 0, $e);
    }
    return $pdo;
}