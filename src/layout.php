<?php

declare(strict_types=1);

function layout_header(string $title, string $area = 'work'): void
{
    $user = current_user();
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — АИС «СРА»</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="area-<?= e($area) ?>">
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/index.php">АИС «СРА»<span class="brand-sub">Справочник российских автомобилей</span></a>
        <?php if ($area === 'admin'): ?>
            <span class="admin-tag">Администрирование</span>
        <?php endif; ?>
        <nav class="nav">
            <?php if ($user && $area === 'work'): ?>
                <a href="/index.php">Поиск</a>
                <a href="/entity.php?type=manufacturers">Производители</a>
                <a href="/entity.php?type=car_models">Модели</a>
                <a href="/entity.php?type=modifications">Модификации</a>
                <a href="/documents.php">Документы</a>
                <?php if (in_array($user['role'], ['operator', 'admin'], true)): ?>
                    <a href="/data.php">Импорт/экспорт</a>
                <?php endif; ?>
                <a href="/profile.php">Профиль</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a class="nav-admin" href="/admin/index.php">Админ-область</a>
                <?php endif; ?>
            <?php elseif ($user && $area === 'admin'): ?>
                <a href="/admin/index.php">Обзор</a>
                <a href="/admin/users.php">Пользователи</a>
                <a href="/admin/audit.php">Журнал</a>
                <a href="/admin/templates.php">Шаблоны</a>
                <a class="nav-admin" href="/index.php">В рабочую область</a>
            <?php else: ?>
                <a href="/login.php">Вход</a>
                <a href="/register.php">Регистрация</a>
            <?php endif; ?>
        </nav>
        <?php if ($user): ?>
            <form class="nav-logout" method="post" action="/logout.php">
                <?= csrf_field() ?>
                <span class="nav-user"><?= e($user['login']) ?> · <?= e(role_label($user['role'])) ?></span>
                <button class="btn btn-small btn-secondary" type="submit">Выход</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main class="container">
<?php foreach (flash_take() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>">
        <span><?= nl2br(e($f['msg'])) ?></span>
        <button type="button" class="flash-close" aria-label="Закрыть">×</button>
    </div>
<?php endforeach; ?>
<?php
}

function layout_footer(): void
{
    ?>
</main>
<footer class="footer">АИС «Справочник российских автомобилей» · учебный проект · PHP 8.4 + MariaDB · время сервера: <?= e(date('d.m.Y H:i')) ?></footer>
<script src="/assets/app.js"></script>
</body>
</html>
<?php
}

function errors_block(array $errors): void
{
    if ($errors === []) {
        return;
    }
    echo '<div class="flash flash-error"><ul class="error-list">';
    foreach ($errors as $msg) {
        echo '<li>' . e($msg) . '</li>';
    }
    echo '</ul></div>';
}

function password_rules_html(string $inputId, ?string $confirmId = null, bool $optional = false): void
{
    ?>
<ul class="pw-rules" data-for="<?= e($inputId) ?>"<?= $optional ? ' data-optional="1"' : '' ?>>
    <?php foreach (password_rules() as $key => $label): ?>
        <li data-rule="<?= e($key) ?>"><span class="pw-mark">✗</span> <?= e($label) ?></li>
    <?php endforeach; ?>
</ul>
<?php if ($confirmId !== null): ?>
<p class="pw-match" data-password="<?= e($inputId) ?>" data-confirm="<?= e($confirmId) ?>"><span class="pw-mark">✗</span> Пароль и подтверждение совпадают</p>
<?php endif;
}

function field_html(array $def, string $name, mixed $value, array $options = []): void
{
    $id = 'f_' . $name;
    echo '<div class="form-row"><label for="' . e($id) . '">' . e($def['label'])
        . (!empty($def['required']) ? ' <span class="req">*</span>' : '') . '</label>';
    if ($def['type'] === 'select' || $def['type'] === 'fk') {
        echo '<select id="' . e($id) . '" name="' . e($name) . '">';
        if (empty($def['required'])) {
            echo '<option value="">— не указано —</option>';
        }
        foreach ($options as $key => $label) {
            $selected = (string) $key === (string) $value ? ' selected' : '';
            echo '<option value="' . e((string) $key) . '"' . $selected . '>' . e((string) $label) . '</option>';
        }
        echo '</select>';
    } elseif ($def['type'] === 'int') {
        echo '<input type="number" id="' . e($id) . '" name="' . e($name) . '" value="' . e((string) ($value ?? ''))
            . '" min="' . (int) $def['min'] . '" max="' . (int) $def['max'] . '" step="1"'
            . (!empty($def['required']) ? ' required' : '') . '>';
    } else {
        echo '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e((string) ($value ?? ''))
            . '" maxlength="' . (int) ($def['max'] ?? 120) . '"'
            . (!empty($def['required']) ? ' required' : '') . '>';
    }
    echo '</div>';
}

function status_badge(string $status): void
{
    echo '<span class="badge badge-' . e($status) . '">' . e(status_label($status)) . '</span>';
}

function captcha_html(): void
{
    if (captcha_tokens() === []) {
        captcha_generate();
    }
    $tokens = captcha_tokens();
    $ghostSrc = '/captcha.php?t=' . urlencode($tokens[0]);
    ?>
<div class="captcha" data-captcha
     data-tokens="<?= e(json_encode($tokens, JSON_UNESCAPED_UNICODE)) ?>"
     data-src="/captcha.php"
     data-csrf="<?= e(csrf_token()) ?>">
    <p class="captcha-task">Соберите изображение: перетащите фрагменты в ячейки 2×2 или кликните фрагмент, затем ячейку.</p>
    <div class="captcha-board">
        <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="captcha-cell" data-cell="<?= $i ?>">
                <img class="captcha-ghost" src="<?= e($ghostSrc) ?>" alt="" aria-hidden="true">
            </div>
        <?php endfor; ?>
    </div>
    <div class="captcha-tray" data-captcha-tray></div>
    <div class="captcha-controls">
        <button type="button" class="btn btn-small btn-secondary" data-captcha-refresh>Обновить капчу</button>
        <button type="button" class="btn btn-small btn-secondary" data-captcha-reset>Сбросить сборку</button>
    </div>
    <p class="captcha-hint hidden" data-captcha-hint>Заполните все четыре ячейки, затем отправьте форму.</p>
    <p class="captcha-warning hidden" data-captcha-warning>Капча устарела или сессия потеряна. Нажмите «Обновить капчу» или перезагрузите страницу.</p>
    <input type="hidden" name="captcha[0]" value="" data-captcha-cell="0">
    <input type="hidden" name="captcha[1]" value="" data-captcha-cell="1">
    <input type="hidden" name="captcha[2]" value="" data-captcha-cell="2">
    <input type="hidden" name="captcha[3]" value="" data-captcha-cell="3">
</div>
<?php
}