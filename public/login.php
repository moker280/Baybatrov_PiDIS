<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (current_user()) {
    redirect('/public/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $place = $_POST['captcha'] ?? null;
    if (!captcha_verify($place)) {
        flash_add('error', 'Капча собрана неверно или устарела. Задание обновлено — попробуйте ещё раз.');
        redirect('/public/login.php');
    }
    $result = auth_attempt(post_str('login'), (string) ($_POST['password'] ?? ''));
    if ($result['error'] !== null) {
        flash_add('error', $result['error']);
        redirect('/public/login.php');
    }
    auth_begin_second_factor((int) $result['user']['id']);
    redirect('/public/verify.php');
}

// Возврат на первый этап всегда очищает незавершённое состояние входа.
auth_clear_pending();
if (captcha_tokens() === []) {
    captcha_generate();
}

layout_header('Вход', 'guest');
?>
<div class="auth-wrap">
    <div class="card auth-card">
        <h1>Вход в систему</h1>
        <p class="muted">Этап 1 из 2: логин, пароль и капча. После проверки откроется страница одноразового кода.</p>
        <form method="post" action="/login.php">
            <?= csrf_field() ?>
            <div class="form-row">
                <label for="login">Логин</label>
                <input type="text" id="login" name="login" required maxlength="64" autocomplete="username">
            </div>
            <div class="form-row">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <?php captcha_html() ?>
            <div class="form-actions">
                <button class="btn" type="submit">Продолжить</button>
                <a class="btn-link" href="/register.php">Регистрация</a>
            </div>
        </form>
    </div>
</div>
<?php
layout_footer();