<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = require_role(['admin']);

$stats = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM users) AS users_total,
        (SELECT COUNT(*) FROM users WHERE role = \'admin\') AS admins,
        (SELECT COUNT(*) FROM users WHERE role = \'operator\') AS operators,
        (SELECT COUNT(*) FROM users WHERE is_locked = 1 AND lock_until > NOW()) AS locked,
        (SELECT COUNT(*) FROM audit_log) AS audit_total,
        (SELECT COUNT(*) FROM audit_log WHERE `timestamp` >= CURDATE()) AS audit_today'
)->fetch();
$recent = db()->query(
    'SELECT a.*, u.login FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 10'
)->fetchAll();

layout_header('Администрирование', 'admin');
?>
<h1>Админ-область</h1>
<div class="stats">
    <div class="stat"><span class="stat-value"><?= (int) $stats['users_total'] ?></span><span class="stat-label">Учётных записей</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['admins'] ?></span><span class="stat-label">Администраторов</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['operators'] ?></span><span class="stat-label">Операторов</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['locked'] ?></span><span class="stat-label">Заблокировано</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['audit_today'] ?></span><span class="stat-label">Событий сегодня</span></div>
</div>
<div class="card">
    <h2>Последние события журнала</h2>
    <?php if ($recent === []): ?>
        <p class="empty">Журнал пуст. События появятся по мере работы с системой.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th>№</th><th>Время</th><th>Пользователь</th><th>Действие</th><th>Сущность</th><th>ID записи</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><?= e(fmt_dt($row['timestamp'])) ?></td>
                        <td><?= e($row['login'] ?? 'система') ?></td>
                        <td><?= e(audit_action_label($row['action'])) ?></td>
                        <td><?= e($row['entity_type'] ?? '—') ?></td>
                        <td><?= $row['entity_id'] === null ? '—' : (int) $row['entity_id'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p><a class="btn" href="/admin/audit.php">Открыть журнал</a></p>
</div>
<?php
layout_footer();