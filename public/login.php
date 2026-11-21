<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (current_user()) {
    redirect('/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $place = $_POST['captcha'] ?? null;
    if (!captcha_verify($place)) {
        flash_add('error', 'Капча собрана неверно или устарела. Задание обновлено — попробуйте ещё раз.');
        redirect('/login.php');
    }
    $result = auth_attempt(post_str('login'), (string) ($_POST['password'] ?? ''));
    if ($result['error'] !== null) {
        flash_add('error', $result['error']);
        redirect('/login.php');
    }
    auth_begin_second_factor((int) $result['user']['id']);
    redirect('/verify.php');
}

auth_clear_pending();
if (captcha_tokens() === []) {
    captcha_generate();
}
$demoAccounts = demo_accounts();

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
        <?php if ($demoAccounts !== []): ?>
            <div class="demo-panel">
                <div class="demo-panel-head">
                    <span class="demo-panel-title">Тестовые данные</span>
                    <span class="demo-panel-note">учебный стенд · значения из базы данных</span>
                </div>
                <ul class="demo-list">
                    <?php foreach ($demoAccounts as $account): ?>
                        <li class="demo-item">
                            <span class="demo-dot demo-dot-<?= e($account['role']) ?>"></span>
                            <span class="demo-cred"><?= e($account['label']) ?>: <?= e($account['login']) ?>/<?= $account['password'] === null ? '••••••' : e($account['password']) ?></span>
                            <?php if ($account['locked']): ?>
                                <span class="demo-state">заблокирован до <?= e($account['lock_until']) ?></span>
                            <?php elseif ($account['password'] === null): ?>
                                <span class="demo-state">пароль изменён вне системы</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($demoAccounts !== []): ?>
    <div class="demo-fab" data-demo-accounts="<?= e(json_encode($demoAccounts, JSON_UNESCAPED_UNICODE)) ?>"
         aria-label="Автозаполнение тестовой учётной записи">
        <p class="demo-fab-status hidden" data-demo-status></p>
        <div class="demo-fab-buttons">
            <?php foreach ($demoAccounts as $account): ?>
                <button type="button" class="btn btn-small demo-btn demo-btn-<?= e($account['role']) ?>"
                        data-demo-role="<?= e($account['role']) ?>"
                    <?= ($account['password'] === null || $account['locked']) ? 'disabled' : '' ?>>
                    <?= e($account['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php
layout_footer();