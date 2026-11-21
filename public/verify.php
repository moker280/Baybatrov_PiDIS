<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (current_user()) {
    redirect('/index.php');
}

$user = auth_pending_user();
if (!$user) {
    auth_clear_pending();
    flash_add('info', 'Начните вход заново: этап ввода кода доступен только после проверки пароля и капчи.');
    redirect('/login.php');
}
if ((int) $user['is_locked'] === 1) {
    auth_clear_pending();
    flash_add('error', 'Учётная запись заблокирована до ' . fmt_dt($user['lock_until']) . '.');
    redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');
    if ($action === 'back') {
        auth_clear_pending();
        redirect('/login.php');
    }
    if ($action === 'resend') {
        // Перевыдача не сбрасывает счётчик неверных кодов — обойти лимит попыток нельзя.
        auth_issue_code();
        flash_add('info', 'Сформирован новый код. Предыдущий код больше недействителен.');
        redirect('/verify.php');
    }
    if ($action === 'code') {
        $otp = $_SESSION['otp'] ?? null;
        if (!$otp) {
            auth_clear_pending();
            flash_add('error', 'Сессия входа устарела. Начните вход заново.');
            redirect('/login.php');
        }
        if (time() > (int) $otp['expires']) {
            flash_add('error', 'Срок действия кода истёк. Получите новый код.');
            redirect('/verify.php');
        }
        $code = post_str('code');
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            flash_add('error', 'Введите код из шести цифр.');
            redirect('/verify.php');
        }
        if (!password_verify($code, (string) $otp['hash'])) {
            $fails = (int) $user['code_fail_count'] + 1;
            db()->prepare('UPDATE users SET code_fail_count = ? WHERE id = ?')->execute([$fails, $user['id']]);
            log_action('code_failed', 'users', (int) $user['id'], null, ['fails' => $fails], (int) $user['id']);
            if ($fails >= (int) cfg('security.max_attempts', 3)) {
                auth_lock_user((int) $user['id']);
                auth_clear_pending();
                flash_add('error', 'Три неверных кода. Учётная запись заблокирована на 60 минут.');
                redirect('/login.php');
            }
            flash_add('error', 'Неверный код. Осталось попыток: ' . ((int) cfg('security.max_attempts', 3) - $fails) . '.');
            redirect('/verify.php');
        }
        // Код использован — повторно применить его нельзя.
        unset($_SESSION['otp']);
        auth_finish_login($user);
        flash_add('success', 'Вы успешно авторизовались.');
        redirect('/index.php');
    }
    redirect('/verify.php');
}

$otp = $_SESSION['otp'] ?? null;
if (!$otp) {
    auth_issue_code();
    $otp = $_SESSION['otp'];
}
$attemptsLeft = max(0, (int) cfg('security.max_attempts', 3) - (int) $user['code_fail_count']);

layout_header('Подтверждение входа', 'guest');
?>
<div class="auth-wrap">
    <div class="card auth-card">
        <h1>Подтверждение входа</h1>
        <p class="muted">Этап 2 из 2 для учётной записи <b><?= e($user['login']) ?></b>. Введите шестизначный код.</p>
        <?php if (cfg('app.demo_mode')): ?>
            <div class="demo-code">Демо-код: <b><?= e((string) $otp['code']) ?></b></div>
            <p class="demo-note">Внимание: вывод кода на этом экране — учебная имитация второго фактора,
                а не полноценная независимая 2FA. В реальной системе код передаётся по независимому каналу.</p>
        <?php endif; ?>
        <p>Срок действия кода:
            <span class="countdown" data-countdown data-expires="<?= (int) $otp['expires'] ?>" data-now="<?= time() ?>"></span>
        </p>
        <p class="muted">Осталось попыток ввода: <?= $attemptsLeft ?>. После трёх неверных кодов учётная запись блокируется на 60 минут.</p>
        <form method="post" action="/verify.php" data-code-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="code">
            <div class="form-row">
                <label for="code">Одноразовый код</label>
                <input class="code-input" type="text" id="code" name="code" required maxlength="6"
                       inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code">
            </div>
            <p class="captcha-hint hidden" data-expired-hint>Код истёк. Нажмите «Получить новый код».</p>
            <div class="form-actions">
                <button class="btn" type="submit">Войти</button>
            </div>
        </form>
        <div class="form-actions">
            <form method="post" action="/verify.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend">
                <button class="btn btn-secondary" type="submit">Получить новый код</button>
            </form>
            <form method="post" action="/verify.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="back">
                <button class="btn btn-secondary" type="submit">Назад к вводу пароля</button>
            </form>
        </div>
    </div>
</div>
<?php
layout_footer();