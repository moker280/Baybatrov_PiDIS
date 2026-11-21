<?php

declare(strict_types=1);

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['user_id'])) {
        return $user = null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        auth_normalize_lock($row);
    }
    return $user = $row ?: null;
}

function auth_normalize_lock(array &$user): void
{
    // Автоматическое окончание временной блокировки.
    if ((int) $user['is_locked'] === 1 && strtotime((string) $user['lock_until']) <= time()) {
        db()->prepare(
            'UPDATE users SET is_locked = 0, lock_until = NULL, login_fail_count = 0, code_fail_count = 0 WHERE id = ?'
        )->execute([$user['id']]);
        log_action('unlock', 'users', (int) $user['id'], null, ['reason' => 'истёк срок блокировки']);
        $user['is_locked'] = 0;
        $user['lock_until'] = null;
        $user['login_fail_count'] = 0;
        $user['code_fail_count'] = 0;
    }
}

function password_expired(array $user): bool
{
    $changed = strtotime((string) $user['last_password_change']);
    return $changed === false || $changed < time() - 86400 * (int) cfg('security.password_days', 90);
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    if ((int) $user['is_locked'] === 1) {
        auth_force_logout('Учётная запись заблокирована до ' . fmt_dt($user['lock_until']) . '.');
    }
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (password_expired($user) && !in_array($page, ['profile.php', 'logout.php'], true)) {
        redirect('/profile.php?forced=1');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        $area = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? 'admin' : 'work';
        layout_header('Доступ запрещён', $area);
        echo '<div class="card"><h1>403 — доступ запрещён</h1><p>Раздел доступен только ролям: '
            . e(implode(', ', array_map('role_label', $roles))) . '.</p>'
            . '<p><a class="btn" href="/index.php">В рабочую область</a></p></div>';
        layout_footer();
        exit;
    }
    return $user;
}

function auth_force_logout(string $message): never
{
    $_SESSION = [];
    session_regenerate_id(true);
    flash_add('error', $message);
    redirect('/login.php');
}

function auth_clear_pending(): void
{
    unset($_SESSION['pending_user_id'], $_SESSION['otp']);
}

function auth_pending_user(): ?array
{
    if (empty($_SESSION['pending_user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['pending_user_id']]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        auth_normalize_lock($row);
    }
    return $row;
}

function auth_lock_user(int $userId, ?int $actorId = null): void
{
    $seconds = (int) cfg('security.lock_seconds', 3600);
    call_procedure('CALL sp_lock_user(?, ?)', [$userId, $seconds]);
    log_action('lock', 'users', $userId, null, [
        'lock_until' => date('Y-m-d H:i:s', time() + $seconds),
        'seconds' => $seconds,
    ], $actorId);
}

function auth_attempt(string $login, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE login = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        auth_normalize_lock($user);
        if ((int) $user['is_locked'] === 1) {
            return ['user' => null, 'error' => 'Учётная запись заблокирована до ' . fmt_dt($user['lock_until']) . '.'];
        }
    }
    // Если логин не найден, всё равно выполняем password_verify — выравниваем время ответа.
    $hash = $user['password_hash'] ?? password_hash('timing-dummy', PASSWORD_DEFAULT);
    $valid = password_verify($password, $hash);
    if (!$user || !$valid) {
        if ($user) {
            $fails = (int) $user['login_fail_count'] + 1;
            db()->prepare('UPDATE users SET login_fail_count = ? WHERE id = ?')->execute([$fails, $user['id']]);
            log_action('login_failed', 'users', (int) $user['id'], null, ['fails' => $fails], (int) $user['id']);
            if ($fails >= (int) cfg('security.max_attempts', 3)) {
                auth_lock_user((int) $user['id']);
                return ['user' => null, 'error' => 'Три неудачные попытки входа. Учётная запись заблокирована на 60 минут.'];
            }
        }
        return ['user' => null, 'error' => 'Неверный логин или пароль.'];
    }
    db()->prepare('UPDATE users SET login_fail_count = 0 WHERE id = ?')->execute([$user['id']]);
    if (password_needs_rehash($user['password_hash'], app_password_algo())) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, app_password_algo()), $user['id']]);
    }
    return ['user' => $user, 'error' => null];
}

function auth_issue_code(): void
{
    // Ведущие нули сохраняются: код — строка из 6 цифр.
    $code = sprintf('%06d', random_int(0, 999999));
    $_SESSION['otp'] = [
        'code' => $code, // только для учебной демонстрации на экране; в реальной 2FA уходит по независимому каналу
        'hash' => password_hash($code, app_password_algo()),
        'expires' => time() + (int) cfg('security.otp_ttl', 300),
    ];
}

function auth_begin_second_factor(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['pending_user_id'] = $userId;
    auth_issue_code();
}

function auth_finish_login(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_user_id'], $_SESSION['otp'], $_SESSION['captcha']);
    $_SESSION['user_id'] = (int) $user['id'];
    db()->prepare('UPDATE users SET login_fail_count = 0, code_fail_count = 0 WHERE id = ?')->execute([$user['id']]);
    log_action('login', 'users', (int) $user['id'], null, null, (int) $user['id']);
}

function auth_logout(): void
{
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($uid !== null) {
        log_action('logout', 'users', $uid, null, null, $uid);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}