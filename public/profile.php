<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();
$forced = get_str('forced') === '1' || password_expired($user);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password2'] ?? '');
    if (!password_verify($current, (string) $user['password_hash'])) {
        $errors[] = 'Текущий пароль указан неверно.';
    }
    foreach (password_policy_errors($new) as $message) {
        $errors[] = 'Новый пароль: ' . $message . '.';
    }
    if ($new !== $confirm) {
        $errors[] = 'Новый пароль и подтверждение не совпадают.';
    }
    if ($new !== '' && $new === $current) {
        $errors[] = 'Новый пароль совпадает с текущим.';
    }
    if ($errors === []) {
        $before = $user;
        $hash = password_hash($new, app_password_algo());
        $now = date('Y-m-d H:i:s');
        // У тестовой записи синхронизируем демо-пароль, чтобы автозаполнение осталось рабочим.
        $demoPassword = (int) $user['is_demo'] === 1 ? encrypt_field($new) : $user['demo_password'];
        db()->prepare('UPDATE users SET password_hash = ?, last_password_change = ?, demo_password = ? WHERE id = ?')
            ->execute([$hash, $now, $demoPassword, $user['id']]);
        $user['password_hash'] = $hash;
        $user['last_password_change'] = $now;
        $user['demo_password'] = $demoPassword;
        log_action('update', 'users', (int) $user['id'], $before, $user);
        session_regenerate_id(true);
        flash_add('success', 'Пароль изменён. Следующая плановая смена — через ' . (int) cfg('security.password_days', 90) . ' дней.');
        redirect('/profile.php');
    }
}

layout_header('Профиль');
?>
<h1>Профиль</h1>
<?php if ($forced): ?>
    <div class="flash flash-error">
        <span>Срок действия пароля истёк (более <?= (int) cfg('security.password_days', 90) ?> дней).
            Доступ к остальным разделам закрыт до смены пароля.</span>
    </div>
<?php endif; ?>
<div class="grid-2">
    <div class="card">
        <h2>Учётная запись</h2>
        <table class="props">
            <tr><th>Логин</th><td><?= e($user['login']) ?></td></tr>
            <tr><th>Роль</th><td><?= e(role_label($user['role'])) ?></td></tr>
            <tr><th>Электронная почта</th><td><?= e(decrypt_field($user['email']) ?? '—') ?></td></tr>
            <tr><th>Телефон</th><td><?= e(decrypt_field($user['phone']) ?? '—') ?></td></tr>
            <tr><th>Создана</th><td><?= e(fmt_dt($user['created_at'])) ?></td></tr>
            <tr><th>Последняя смена пароля</th><td><?= e(fmt_dt($user['last_password_change'])) ?></td></tr>
        </table>
        <p class="muted">Почта и телефон хранятся в БД в зашифрованном виде (AES-256-GCM).</p>
    </div>
    <div class="card">
        <h2>Смена пароля</h2>
        <?php errors_block($errors) ?>
        <form method="post" action="/profile.php">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="current_password">Текущий пароль <span class="req">*</span></label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-row">
                <label for="new_password">Новый пароль <span class="req">*</span></label>
                <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
            </div>
            <div class="form-row">
                <label for="new_password2">Повторите новый пароль <span class="req">*</span></label>
                <input type="password" id="new_password2" name="new_password2" required autocomplete="new-password">
            </div>
            <?php password_rules_html('new_password', 'new_password2') ?>
            <div class="form-actions">
                <button class="btn" type="submit">Сменить пароль</button>
            </div>
        </form>
    </div>
</div>
<?php
layout_footer();