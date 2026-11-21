<?php

declare(strict_types=1);

function cfg(string $key, mixed $default = null): mixed
{
    $value = APP_CONFIG;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_log_write(string $message): void
{
    file_put_contents(APP_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_add(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_take(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Запрос отклонён: неверный CSRF-токен. Обновите страницу и повторите действие.');
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'CLI');
}

function post_str(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function get_str(string $key): string
{
    return trim((string) ($_GET[$key] ?? ''));
}

function fmt_dt(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('d.m.Y H:i', $ts);
}

function role_label(string $role): string
{
    return ['admin' => 'Администратор', 'operator' => 'Оператор', 'user' => 'Пользователь'][$role] ?? $role;
}

function status_label(string $status): string
{
    return [
        'active' => 'Действует',
        'archived' => 'Архив',
        'in_production' => 'Производится',
        'discontinued' => 'Снят с производства',
    ][$status] ?? $status;
}

function data_key(): string
{
    $key = hex2bin((string) cfg('security.data_key', ''));
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Некорректный ключ шифрования: укажите в config.php 64 hex-символа.');
    }
    return $key;
}

function encrypt_field(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', data_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Ошибка шифрования данных.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_field(?string $encoded): ?string
{
    if ($encoded === null || $encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        return '[не расшифровано]';
    }
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        data_key(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    if ($plain === false) {
        app_log_write('decrypt_field: значение не расшифровано (проверьте ключ security.data_key)');
        return '[не расшифровано]';
    }
    return $plain;
}

function app_password_algo(): string
{
    // ТЗ 3.2.9 — Argon2id; если сборка PHP без поддержки, используется bcrypt (PASSWORD_DEFAULT).
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function password_rules(): array
{
    return [
        'len' => 'Не менее 6 символов',
        'dig' => 'Хотя бы одна цифра (0–9)',
        'low' => 'Хотя бы одна строчная буква (латиница или кириллица)',
        'up' => 'Хотя бы одна заглавная буква (латиница или кириллица)',
        'seq' => 'Нет одинаковых соседних символов и соседних букв/цифр по коду (aa, aA, 11, ab, ba, 12, 21, 89 запрещены)',
    ];
}

function password_has_forbidden_sequence(string $password): bool
{
    // Зеркало клиентского JS: проверка по строке в нижнем регистре, по кодовым точкам Unicode.
    $chars = preg_split('//u', mb_strtolower($password, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n = count($chars);
    for ($i = 0; $i + 1 < $n; $i++) {
        $a = $chars[$i];
        $b = $chars[$i + 1];
        if ($a === $b) {
            return true;
        }
        $sameClass = (ctype_digit($a) && ctype_digit($b))
            || (preg_match('/[a-zа-яё]/u', $a) === 1 && preg_match('/[a-zа-яё]/u', $b) === 1);
        if ($sameClass && abs(mb_ord($b, 'UTF-8') - mb_ord($a, 'UTF-8')) === 1) {
            return true;
        }
    }
    return false;
}

function password_policy_errors(string $password): array
{
    $rules = password_rules();
    $errors = [];
    $chars = preg_split('//u', $password, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($chars) < 6) {
        $errors['len'] = $rules['len'];
    }
    if (preg_match('/[0-9]/u', $password) !== 1) {
        $errors['dig'] = $rules['dig'];
    }
    if (preg_match('/[a-zа-яё]/u', $password) !== 1) {
        $errors['low'] = $rules['low'];
    }
    if (preg_match('/[A-ZА-ЯЁ]/u', $password) !== 1) {
        $errors['up'] = $rules['up'];
    }
    if ($password === '' || password_has_forbidden_sequence($password)) {
        $errors['seq'] = $rules['seq'];
    }
    return $errors;
}

function call_procedure(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // CALL может возвращать служебные пустые наборы — вычитываем их до конца.
    while ($stmt->nextRowset()) {
    }
    $stmt->closeCursor();
    return $rows;
}

function captcha_generate(): void
{
    $slots = [0, 1, 2, 3];
    do {
        shuffle($slots);
    } while ($slots === [0, 1, 2, 3]);
    $map = [];
    foreach ($slots as $slot) {
        $map[bin2hex(random_bytes(8))] = $slot; // случайный токен -> правильная ячейка (0..3)
    }
    $_SESSION['captcha'] = $map;
}

function captcha_tokens(): array
{
    return array_keys($_SESSION['captcha'] ?? []);
}

function captcha_verify(mixed $place): bool
{
    $map = $_SESSION['captcha'] ?? [];
    unset($_SESSION['captcha']); // задание одноразовое: любая попытка его расходует
    if (!is_array($place) || array_keys($place) !== [0, 1, 2, 3] || $map === []) {
        return false;
    }
    $used = [];
    foreach ($place as $cell => $token) {
        if (!is_string($token) || !isset($map[$token]) || $map[$token] !== $cell || isset($used[$token])) {
            return false;
        }
        $used[$token] = true;
    }
    return count($used) === 4;
}

function captcha_image_path(string $token): ?string
{
    $map = $_SESSION['captcha'] ?? [];
    if ($token === '' || !isset($map[$token])) {
        return null;
    }
    $order = cfg('app.captcha_order', [1, 2, 3, 4]);
    if (!is_array($order) || count($order) !== 4) {
        $order = [1, 2, 3, 4];
    }
    $fileNum = (int) ($order[(int) $map[$token]] ?? 0);
    if ($fileNum < 1 || $fileNum > 4) {
        return null;
    }
    $file = APP_ROOT . '/captcha/' . $fileNum . '.png';
    return is_file($file) ? $file : null;
}