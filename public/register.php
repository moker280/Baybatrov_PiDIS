<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (current_user()) {
    redirect('/index.php');
}

$errors = [];
$old = ['login' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['login'] = post_str('login');
    $old['email'] = post_str('email');
    $old['phone'] = post_str('phone');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password2'] ?? '');

    if (!captcha_verify($_POST['captcha'] ?? null)) {
        $errors[] = 'Капча собрана неверно или устарела. Задание обновлено.';
    }
    if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $old['login']) !== 1) {
        $errors[] = 'Логин: 3–64 символа, латинские буквы, цифры, точка, дефис, подчёркивание.';
    }
    if (filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Укажите корректный адрес электронной почты.';
    }
    if ($old['phone'] !== '' && preg_match('/^[0-9+() -]{5,20}$/', $old['phone']) !== 1) {
        $errors[] = 'Телефон: допустимы цифры, пробелы, скобки, дефис и «+» (5–20 символов).';
    }
    foreach (password_policy_errors($password) as $message) {
        $errors[] = 'Пароль: ' . $message . '.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Пароль и подтверждение не совпадают.';
    }
    if ($errors === []) {
        $stmt = db()->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$old['login']]);
        if ($stmt->fetch()) {
            $errors[] = 'Этот логин уже занят. Выберите другой.';
        }
    }
    if ($errors === []) {
        $now = date('Y-m-d H:i:s');
        try {
            $stmt = db()->prepare(
                'INSERT INTO users (login, password_hash, role, email, phone, created_at, last_password_change,
                                    is_locked, lock_until, login_fail_count, code_fail_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 0, 0)'
            );
            // Роль жёстко задана сервером: получить оператора/администратора через форму невозможно.
            $stmt->execute([
                $old['login'],
                password_hash($password, app_password_algo()),
                'user',
                encrypt_field($old['email']),
                $old['phone'] === '' ? null : encrypt_field($old['phone']),
                $now,
                $now,
            ]);
            $id = (int) db()->lastInsertId();
            log_action('create', 'users', $id, null, ['login' => $old['login'], 'role' => 'user', 'source' => 'registration'], $id);
            flash_add('success', 'Регистрация завершена. Войдите с вашим логином и паролем.');
            redirect('/login.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Этот логин уже занят. Выберите другой.';
            } else {
                throw $e;
            }
        }
    }
    captcha_generate(); // задание одноразовое: после любой попытки формируем новое
}

if (captcha_tokens() === []) {
    captcha_generate();
}

layout_header('Регистрация', 'guest');
?>
<div class="auth-wrap auth-wide">
    <div class="card auth-card">
        <h1>Регистрация</h1>
        <p class="muted">Саморегистрация создаёт учётную запись с ролью «Пользователь» (просмотр справочника).</p>
        <?php errors_block($errors) ?>
        <form method="post" action="/register.php">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="login">Логин <span class="req">*</span></label>
                <input type="text" id="login" name="login" required maxlength="64" value="<?= e($old['login']) ?>" autocomplete="username">
            </div>
            <div class="form-row">
                <label for="email">Электронная почта <span class="req">*</span></label>
                <input type="email" id="email" name="email" required maxlength="254" value="<?= e($old['email']) ?>">
            </div>
            <div class="form-row">
                <label for="phone">Телефон</label>
                <input type="text" id="phone" name="phone" maxlength="20" value="<?= e($old['phone']) ?>">
            </div>
            <div class="form-row">
                <label for="reg_password">Пароль <span class="req">*</span></label>
                <input type="password" id="reg_password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-row">
                <label for="reg_password2">Повторите пароль <span class="req">*</span></label>
                <input type="password" id="reg_password2" name="password2" required autocomplete="new-password">
            </div>
            <?php password_rules_html('reg_password', 'reg_password2') ?>
            <?php captcha_html() ?>
            <div class="form-actions">
                <button class="btn" type="submit">Зарегистрироваться</button>
                <a class="btn-link" href="/login.php">У меня есть учётная запись</a>
            </div>
        </form>
    </div>
</div>
<?php
layout_footer();