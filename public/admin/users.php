<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = require_role(['admin']);
$errors = [];
$formData = null;
$editId = 0;
$editRow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');
    $id = (int) post_str('id');

    if ($action === 'save') {
        $editId = $id;
        $editRow = null;
        if ($id > 0) {
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $editRow = $stmt->fetch() ?: null;
            if (!$editRow) {
                flash_add('error', 'Пользователь не найден.');
                redirect('/admin/users.php');
            }
        }
        $login = post_str('login');
        $email = post_str('email');
        $phone = post_str('phone');
        $role = post_str('role');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password2'] ?? '');

        if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $login) !== 1) {
            $errors[] = 'Логин: 3–64 символа (латиница, цифры, точка, дефис, подчёркивание).';
        }
        if (!in_array($role, ['user', 'operator', 'admin'], true)) {
            $errors[] = 'Недопустимая роль.';
        }
        // Роль администратора неизменяема: защита от потери единственного администратора.
        if ($editRow && $editRow['role'] === 'admin') {
            $role = 'admin';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Укажите корректный адрес электронной почты.';
        }
        if ($phone !== '' && preg_match('/^[0-9+() -]{5,20}$/', $phone) !== 1) {
            $errors[] = 'Телефон: цифры, пробелы, скобки, дефис, «+» (5–20 символов).';
        }
        $setPassword = !$editRow || $password !== '';
        if ($setPassword) {
            foreach (password_policy_errors($password) as $message) {
                $errors[] = 'Пароль: ' . $message . '.';
            }
            if ($password !== $confirm) {
                $errors[] = 'Пароль и подтверждение не совпадают.';
            }
        }
        if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $login) === 1) {
            $stmt = db()->prepare('SELECT id FROM users WHERE login = ? AND id <> ?');
            $stmt->execute([$login, $editRow ? (int) $editRow['id'] : 0]);
            if ($stmt->fetch()) {
                $errors[] = 'Логин уже занят.';
            }
        }
        $formData = ['login' => $login, 'email' => $email, 'phone' => $phone, 'role' => $role];
        if ($errors === []) {
            $now = date('Y-m-d H:i:s');
            try {
                if ($editRow) {
                    $hash = $setPassword ? password_hash($password, app_password_algo()) : $editRow['password_hash'];
                    $lastChange = $setPassword ? $now : $editRow['last_password_change'];
                    // Демо-пароль обновляется только у тестовой записи и только при задании пароля.
                    $demoPassword = ($setPassword && (int) $editRow['is_demo'] === 1)
                        ? encrypt_field($password)
                        : $editRow['demo_password'];
                    db()->prepare(
                        'UPDATE users SET login = ?, role = ?, email = ?, phone = ?, password_hash = ?,
                                          last_password_change = ?, demo_password = ? WHERE id = ?'
                    )->execute([
                        $login, $role, encrypt_field($email), $phone === '' ? null : encrypt_field($phone),
                        $hash, $lastChange, $demoPassword, $id,
                    ]);
                    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
                    $stmt->execute([$id]);
                    log_action('update', 'users', $id, $editRow, $stmt->fetch());
                    flash_add('success', 'Пользователь обновлён.' . ($setPassword ? ' Пароль задан администратором.' : ''));
                } else {
                    db()->prepare(
                        'INSERT INTO users (login, password_hash, role, email, phone, created_at, last_password_change,
                                            is_locked, lock_until, login_fail_count, code_fail_count, is_demo, demo_password)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 0, 0, 0, NULL)'
                    )->execute([$login, password_hash($password, app_password_algo()), $role,
                        encrypt_field($email), $phone === '' ? null : encrypt_field($phone), $now, $now]);
                    $newId = (int) db()->lastInsertId();
                    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
                    $stmt->execute([$newId]);
                    log_action('create', 'users', $newId, null, $stmt->fetch());
                    flash_add('success', 'Пользователь создан.');
                }
                redirect('/admin/users.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'Логин уже занят.';
                } else {
                    throw $e;
                }
            }
        }
    } elseif ($action === 'lock') {
        if ($id === (int) $user['id']) {
            flash_add('error', 'Нельзя заблокировать собственную учётную запись.');
        } else {
            auth_lock_user($id, (int) $user['id']);
            flash_add('success', 'Учётная запись заблокирована на 60 минут.');
        }
        redirect('/admin/users.php');
    } elseif ($action === 'unlock') {
        db()->prepare('UPDATE users SET is_locked = 0, lock_until = NULL, login_fail_count = 0, code_fail_count = 0 WHERE id = ?')
            ->execute([$id]);
        log_action('unlock', 'users', $id, null, ['reason' => 'снято администратором']);
        flash_add('success', 'Блокировка снята, счётчики попыток сброшены.');
        redirect('/admin/users.php');
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            flash_add('error', 'Пользователь не найден.');
        } elseif ($id === (int) $user['id']) {
            flash_add('error', 'Нельзя удалить собственную учётную запись.');
        } else {
            db()->prepare('SET @app_user_id = ?')->execute([(int) $user['id']]);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            log_action('delete', 'users', $id, $target, null);
            flash_add('success', 'Пользователь удалён.');
        }
        redirect('/admin/users.php');
    } else {
        redirect('/admin/users.php');
    }
}

$showForm = $formData !== null;
if (!$showForm) {
    if (get_str('new') === '1') {
        $showForm = true;
        $formData = ['login' => '', 'email' => '', 'phone' => '', 'role' => 'user'];
    } elseif (get_str('edit') !== '') {
        $editId = (int) get_str('edit');
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$editId]);
        $editRow = $stmt->fetch() ?: null;
        if ($editRow) {
            $showForm = true;
            $formData = [
                'login' => $editRow['login'],
                'email' => decrypt_field($editRow['email']) ?? '',
                'phone' => decrypt_field($editRow['phone']) ?? '',
                'role' => $editRow['role'],
            ];
        } else {
            flash_add('error', 'Пользователь не найден.');
            redirect('/admin/users.php');
        }
    }
}

layout_header('Пользователи', 'admin');
?>
<h1>Пользователи</h1>
<?php if ($showForm): ?>
    <div class="card">
        <h2><?= $editId > 0 ? 'Изменение пользователя №' . $editId : 'Новый пользователь' ?></h2>
        <?php errors_block($errors) ?>
        <form method="post" action="/admin/users.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editId ?>">
            <div class="form-row">
                <label for="u_login">Логин <span class="req">*</span></label>
                <input type="text" id="u_login" name="login" required maxlength="64"
                       value="<?= e($formData['login']) ?>">
            </div>
            <div class="form-row">
                <label for="u_role">Роль <span class="req">*</span></label>
                <?php if ($editRow && $editRow['role'] === 'admin'): ?>
                    <input type="text" id="u_role" value="Администратор (роль защищена от понижения)" readonly>
                    <input type="hidden" name="role" value="admin">
                <?php else: ?>
                    <select id="u_role" name="role">
                        <?php foreach (['user', 'operator', 'admin'] as $r): ?>
                            <option value="<?= e($r) ?>"<?= $formData['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="form-row">
                <label for="u_email">Электронная почта <span class="req">*</span></label>
                <input type="email" id="u_email" name="email" required maxlength="254" value="<?= e($formData['email']) ?>">
            </div>
            <div class="form-row">
                <label for="u_phone">Телефон</label>
                <input type="text" id="u_phone" name="phone" maxlength="20" value="<?= e($formData['phone']) ?>">
            </div>
            <div class="form-row">
                <label for="user_password"><?= $editId > 0 ? 'Новый пароль (пусто — не менять)' : 'Пароль <span class="req">*</span>' ?></label>
                <input type="password" id="user_password" name="password" autocomplete="new-password" <?= $editId > 0 ? '' : 'required' ?>>
            </div>
            <div class="form-row">
                <label for="user_password2">Повторите пароль</label>
                <input type="password" id="user_password2" name="password2" autocomplete="new-password">
            </div>
            <?php password_rules_html('user_password', 'user_password2', $editId > 0) ?>
            <div class="form-actions">
                <button class="btn" type="submit">Сохранить</button>
                <a class="btn-link" href="/admin/users.php">Отмена</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <p><a class="btn" href="/admin/users.php?new=1">Создать пользователя</a></p>
    <?php
    $fq = get_str('q');
    $frole = get_str('role');
    $flocked = get_str('locked');
    $where = [];
    $params = [];
    if ($fq !== '') {
        $where[] = 'u.login LIKE ?';
        $params[] = '%' . $fq . '%';
    }
    if (in_array($frole, ['user', 'operator', 'admin'], true)) {
        $where[] = 'u.role = ?';
        $params[] = $frole;
    }
    if ($flocked === '1') {
        $where[] = 'u.is_locked = 1 AND u.lock_until > NOW()';
    } elseif ($flocked === '0') {
        $where[] = '(u.is_locked = 0 OR u.lock_until <= NOW())';
    }
    $sql = 'SELECT u.*, fn_is_password_expired(u.id) AS pw_expired FROM users u'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.id LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card">
        <h2>Фильтры</h2>
        <form method="get" action="/admin/users.php" class="filters">
            <div class="form-row">
                <label for="flt_q">Логин содержит</label>
                <input type="text" id="flt_q" name="q" value="<?= e($fq) ?>">
            </div>
            <div class="form-row">
                <label for="flt_role">Роль</label>
                <select id="flt_role" name="role">
                    <option value="">Все</option>
                    <?php foreach (['user', 'operator', 'admin'] as $r): ?>
                        <option value="<?= e($r) ?>"<?= $frole === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="flt_locked">Состояние</label>
                <select id="flt_locked" name="locked">
                    <option value="">Все</option>
                    <option value="1"<?= $flocked === '1' ? ' selected' : '' ?>>Заблокированные</option>
                    <option value="0"<?= $flocked === '0' ? ' selected' : '' ?>>Активные</option>
                </select>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Применить</button>
                <a class="btn-link" href="/admin/users.php">Сбросить</a>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>Учётные записи (<?= count($rows) ?>)</h2>
        <?php if ($rows === []): ?>
            <p class="empty">Записей нет. Создайте пользователя или запустите scripts/seed_users.php.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="list">
                    <thead>
                    <tr><th>ID</th><th>Логин</th><th>Роль</th><th>Почта</th><th>Создан</th><th>Смена пароля</th><th>Состояние</th><th>Действия</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $locked = (int) $row['is_locked'] === 1 && strtotime((string) $row['lock_until']) > time(); ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= e($row['login']) ?></td>
                            <td><span class="badge badge-role-<?= e($row['role']) ?>"><?= e(role_label($row['role'])) ?></span></td>
                            <td><?= e(decrypt_field($row['email']) ?? '—') ?></td>
                            <td><?= e(fmt_dt($row['created_at'])) ?></td>
                            <td><?= e(fmt_dt($row['last_password_change'])) ?><?= (int) $row['pw_expired'] === 1 ? ' <span class="badge badge-discontinued">истёк</span>' : '' ?></td>
                            <td>
                                <?php if ($locked): ?>
                                    <span class="badge badge-discontinued">Заблокирован до <?= e(fmt_dt($row['lock_until'])) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-active">Активен</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a class="btn btn-small btn-secondary" href="/admin/users.php?edit=<?= (int) $row['id'] ?>">Изменить</a>
                                <?php if ($locked): ?>
                                    <form method="post" action="/admin/users.php" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="unlock">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="btn btn-small" type="submit">Разблокировать</button>
                                    </form>
                                <?php elseif ((int) $row['id'] !== (int) $user['id']): ?>
                                    <form method="post" action="/admin/users.php" class="inline-form"
                                          data-confirm="Заблокировать пользователя «<?= e($row['login']) ?>» на 60 минут?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="lock">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="btn btn-small btn-secondary" type="submit">Заблокировать</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int) $row['id'] !== (int) $user['id']): ?>
                                    <form method="post" action="/admin/users.php" class="inline-form"
                                          data-confirm="Удалить пользователя «<?= e($row['login']) ?>»?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="btn btn-small btn-danger" type="submit">Удалить</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php
layout_footer();