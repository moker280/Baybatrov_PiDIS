<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) post_str('id');
    $stmt = db()->prepare('SELECT * FROM audit_log WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_add('error', 'Запись журнала не найдена.');
        redirect('/admin/audit.php');
    }
    if (post_str('action') === 'restore') {
        $error = audit_restore($row);
        if ($error !== null) {
            flash_add('error', $error);
        } else {
            flash_add('success', 'Запись ' . $row['entity_type'] . ' №' . (int) $row['entity_id'] . ' восстановлена из состояния «до».');
        }
        redirect('/admin/audit.php?view=' . $id);
    }
    if (post_str('action') === 'undo_import') {
        $error = audit_undo_import($row);
        if ($error !== null) {
            flash_add('error', $error);
        } else {
            flash_add('success', 'Импорт отменён, состояние восстановлено.');
        }
        redirect('/admin/audit.php?view=' . $id);
    }
    if (post_str('action') === 'delete') {
        // Удаление записи журнала само журналируется (с состоянием «до»).
        $decoded = [
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'],
            'action' => $row['action'],
            'state_before' => audit_state($row['state_before']),
            'state_after' => audit_state($row['state_after']),
        ];
        db()->prepare('DELETE FROM audit_log WHERE id = ?')->execute([$id]);
        log_action('delete', 'audit_log', $id, $decoded, null);
        flash_add('success', 'Запись журнала удалена (событие зафиксировано).');
        redirect('/admin/audit.php');
    }
    redirect('/admin/audit.php');
}

$viewId = (int) get_str('view');
$viewRow = null;
if ($viewId > 0) {
    $stmt = db()->prepare('SELECT a.*, u.login FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?');
    $stmt->execute([$viewId]);
    $viewRow = $stmt->fetch() ?: null;
}

layout_header('Журнал действий', 'admin');
?>
<h1>Журнал действий</h1>
<?php if ($viewRow): ?>
    <?php
    $before = audit_state($viewRow['state_before']);
    $after = audit_state($viewRow['state_after']);
    // Для удобства чтения расшифровываем служебные поля пользователя только в отображении.
    foreach ([&$before, &$after] as &$state) {
        if (is_array($state) && $viewRow['entity_type'] === 'users') {
            foreach (['email', 'phone'] as $k) {
                if (isset($state[$k]) && is_string($state[$k])) {
                    $state[$k] = decrypt_field($state[$k]);
                }
            }
        }
    }
    unset($state);
    ?>
    <div class="card">
        <h2>Запись журнала №<?= (int) $viewRow['id'] ?></h2>
        <table class="props">
            <tr><th>Время</th><td><?= e(fmt_dt($viewRow['timestamp'])) ?></td></tr>
            <tr><th>Пользователь</th><td><?= e($viewRow['login'] ?? 'система / API') ?></td></tr>
            <tr><th>Действие</th><td><?= e(audit_action_label($viewRow['action'])) ?></td></tr>
            <tr><th>Сущность</th><td><?= e($viewRow['entity_type'] ?? '—') ?>, №<?= $viewRow['entity_id'] === null ? '—' : (int) $viewRow['entity_id'] ?></td></tr>
            <tr><th>IP-адрес</th><td><?= e(decrypt_field($viewRow['ip_address']) ?? '—') ?></td></tr>
        </table>
        <div class="grid-2">
            <div>
                <h3>Состояние «до»</h3>
                <pre class="state-json"><?= $before === null ? '—' : e(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
            <div>
                <h3>Состояние «после»</h3>
                <pre class="state-json"><?= $after === null ? '—' : e(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        </div>
        <div class="form-actions">
            <?php if (audit_can_restore($viewRow)): ?>
                <form method="post" action="/admin/audit.php" class="inline-form"
                      data-confirm="Восстановить <?= e($viewRow['entity_type']) ?> №<?= (int) $viewRow['entity_id'] ?> в состояние «до»?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                    <button class="btn" type="submit">Восстановить запись</button>
                </form>
            <?php endif; ?>
            <?php if (audit_is_import_undo($viewRow)): ?>
                <form method="post" action="/admin/audit.php" class="inline-form"
                      data-confirm="Отменить импорт №<?= (int) $viewRow['id'] ?> и вернуть состояние базы?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="undo_import">
                    <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                    <button class="btn" type="submit">Отменить импорт</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin/audit.php" class="inline-form"
                  data-confirm="Удалить запись журнала №<?= (int) $viewRow['id'] ?>? Само удаление будет зафиксировано.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                <button class="btn btn-danger" type="submit">Удалить запись журнала</button>
            </form>
            <a class="btn-link" href="/admin/audit.php">К списку</a>
        </div>
    </div>
<?php else: ?>
    <?php
    $f = [
        'user_id' => (int) get_str('user_id'),
        'entity_type' => get_str('entity_type'),
        'action' => get_str('action'),
        'date_from' => get_str('date_from'),
        'date_to' => get_str('date_to'),
        'q' => get_str('q'),
    ];
    $where = [];
    $params = [];
    if ($f['user_id'] > 0) {
        $where[] = 'a.user_id = ?';
        $params[] = $f['user_id'];
    }
    $entityTypes = ['manufacturers', 'car_models', 'modifications', 'users', 'audit_log'];
    if (in_array($f['entity_type'], $entityTypes, true)) {
        $where[] = 'a.entity_type = ?';
        $params[] = $f['entity_type'];
    }
    $actions = ['login', 'logout', 'create', 'read', 'update', 'delete', 'import', 'export', 'restore',
        'lock', 'unlock', 'login_failed', 'code_failed'];
    if (in_array($f['action'], $actions, true)) {
        $where[] = 'a.action = ?';
        $params[] = $f['action'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_from']) === 1) {
        $where[] = 'a.`timestamp` >= ?';
        $params[] = $f['date_from'] . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date_to']) === 1) {
        $where[] = 'a.`timestamp` <= ?';
        $params[] = $f['date_to'] . ' 23:59:59';
    }
    $sql = 'SELECT a.*, u.login FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.id DESC LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($f['q'] !== '') {
        // Состояния и IP зашифрованы, поэтому текстовый поиск выполняется после расшифровки в PHP.
        $rows = array_values(array_filter($rows, static function (array $row) use ($f): bool {
            $hay = mb_strtolower(
                (string) decrypt_field($row['state_before']) . ' ' . (string) decrypt_field($row['state_after'])
                . ' ' . (string) decrypt_field($row['ip_address'])
            );
            return mb_stripos($hay, $f['q']) !== false;
        }));
    }
    $usersList = db()->query('SELECT id, login FROM users ORDER BY login')->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>
    <div class="card">
        <h2>Фильтры</h2>
        <form method="get" action="/admin/audit.php" class="filters">
            <div class="form-row">
                <label for="flt_user">Пользователь</label>
                <select id="flt_user" name="user_id">
                    <option value="0">Все</option>
                    <?php foreach ($usersList as $uid => $login): ?>
                        <option value="<?= (int) $uid ?>"<?= $f['user_id'] === (int) $uid ? ' selected' : '' ?>><?= e($login) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="flt_entity">Сущность</label>
                <select id="flt_entity" name="entity_type">
                    <option value="">Все</option>
                    <?php foreach ($entityTypes as $t): ?>
                        <option value="<?= e($t) ?>"<?= $f['entity_type'] === $t ? ' selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="flt_action">Действие</label>
                <select id="flt_action" name="action">
                    <option value="">Все</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= e($a) ?>"<?= $f['action'] === $a ? ' selected' : '' ?>><?= e(audit_action_label($a)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="flt_from">Дата с</label>
                <input type="date" id="flt_from" name="date_from" value="<?= e($f['date_from']) ?>">
            </div>
            <div class="form-row">
                <label for="flt_to">по</label>
                <input type="date" id="flt_to" name="date_to" value="<?= e($f['date_to']) ?>">
            </div>
            <div class="form-row">
                <label for="flt_q">Текст в состояниях/IP (расшифровка)</label>
                <input type="text" id="flt_q" name="q" value="<?= e($f['q']) ?>">
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Применить</button>
                <a class="btn-link" href="/admin/audit.php">Сбросить</a>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>События (<?= count($rows) ?><?= count($rows) >= 300 ? ', показаны последние 300' : '' ?>)</h2>
        <?php if ($rows === []): ?>
            <p class="empty">Событий не найдено. Выполните действия в системе или измените фильтры.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>№</th><th>Время</th><th>Пользователь</th><th>Действие</th><th>Сущность</th><th>ID</th><th>Восстановление</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= e(fmt_dt($row['timestamp'])) ?></td>
                            <td><?= e($row['login'] ?? 'система / API') ?></td>
                            <td><?= e(audit_action_label($row['action'])) ?></td>
                            <td><?= e($row['entity_type'] ?? '—') ?></td>
                            <td><?= $row['entity_id'] === null ? '—' : (int) $row['entity_id'] ?></td>
                                            <td><?= (audit_can_restore($row) || audit_is_import_undo($row)) ? '<span class="badge badge-active">доступно</span>' : '—' ?></td>
                            <td><a class="btn btn-small btn-secondary" href="/admin/audit.php?view=<?= (int) $row['id'] ?>">Просмотр</a></td>
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