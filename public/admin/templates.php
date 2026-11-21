<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$user = require_role(['admin']);
$codes = array_keys(template_defaults());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $code = post_str('code');
    if (!in_array($code, $codes, true)) {
        flash_add('error', 'Неизвестный шаблон.');
        redirect('/admin/templates.php');
    }
    $stmt = db()->prepare('SELECT * FROM doc_templates WHERE code = ?');
    $stmt->execute([$code]);
    $before = $stmt->fetch();
    if (!$before) {
        flash_add('error', 'Шаблон не найден в БД.');
        redirect('/admin/templates.php');
    }
    if (post_str('action') === 'reset') {
        $default = template_defaults()[$code];
        db()->prepare('UPDATE doc_templates SET title = ?, body = ? WHERE code = ?')
            ->execute([$default['title'], $default['body'], $code]);
        $stmt->execute([$code]);
        log_action('update', 'doc_templates', (int) $before['id'], $before, $stmt->fetch());
        flash_add('success', 'Шаблон восстановлен к тексту по умолчанию.');
        redirect('/admin/templates.php');
    }
    $title = post_str('title');
    $body = (string) ($_POST['body'] ?? '');
    if ($title === '' || mb_strlen($title) > 120) {
        $errors[] = 'Заголовок: 1–120 символов.';
    }
    if (trim($body) === '' || mb_strlen($body) > 10000) {
        $errors[] = 'Текст шаблона: 1–10000 символов.';
    }
    if ($errors === []) {
        db()->prepare('UPDATE doc_templates SET title = ?, body = ? WHERE code = ?')->execute([$title, $body, $code]);
        $stmt->execute([$code]);
        log_action('update', 'doc_templates', (int) $before['id'], $before, $stmt->fetch());
        flash_add('success', 'Шаблон сохранён.');
        redirect('/admin/templates.php');
    }
    $editRow = ['code' => $code, 'title' => $title, 'body' => $body];
} else {
    $editCode = get_str('edit');
    $editRow = null;
    if (in_array($editCode, $codes, true)) {
        $stmt = db()->prepare('SELECT * FROM doc_templates WHERE code = ?');
        $stmt->execute([$editCode]);
        $editRow = $stmt->fetch() ?: null;
    }
}

$hints = [
    'car_card' => 'Поля: {{manufacturer_name}}, {{country}}, {{founded_year}}, {{model_name}}, {{body_type}}, '
        . '{{production_years}}, {{status_label}}, {{modifications_count}}, {{generated_at}}. '
        . 'Блок {{ROWS}}…{{ENDROWS}} повторяется для каждой модификации: {{code}}, {{engine_type}}, {{engine_volume}}, '
        . '{{power_hp}}, {{transmission}}, {{drive_type}}, {{years}}.',
    'model_info' => 'Поля: {{model_name}}, {{manufacturer_name}}, {{country}}, {{body_type}}, {{production_years}}, '
        . '{{status_label}}, {{modifications_count}}, {{power_range}}, {{generated_at}}.',
    'manufacturer_report' => 'Поля: {{generated_at}}, {{total_manufacturers}}. Блок {{ROWS}}…{{ENDROWS}} повторяется '
        . 'для каждого производителя: {{name}}, {{models_count}}, {{in_production_count}}, {{modifications_count}}, {{avg_power}}.',
];

layout_header('Шаблоны документов', 'admin');
?>
<h1>Шаблоны печатных форм</h1>
<?php if ($editRow): ?>
    <div class="card">
        <h2>Изменение шаблона «<?= e($editRow['title']) ?>»</h2>
        <?php errors_block($errors) ?>
        <p class="muted"><?= e($hints[$editRow['code']]) ?></p>
        <form method="post" action="/admin/templates.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="code" value="<?= e($editRow['code']) ?>">
            <div class="form-row">
                <label for="tpl_title">Заголовок</label>
                <input type="text" id="tpl_title" name="title" required maxlength="120" value="<?= e($editRow['title']) ?>">
            </div>
            <div class="form-row">
                <label for="tpl_body">Текст шаблона (первая строка — заголовок документа)</label>
                <textarea id="tpl_body" name="body" rows="14" required><?= e($editRow['body']) ?></textarea>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Сохранить</button>
                <a class="btn-link" href="/admin/templates.php">Отмена</a>
            </div>
        </form>
        <form method="post" action="/admin/templates.php" class="inline-form"
              data-confirm="Вернуть текст шаблона по умолчанию? Текущий текст будет заменён.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="code" value="<?= e($editRow['code']) ?>">
            <button class="btn btn-secondary" type="submit">Текст по умолчанию</button>
        </form>
    </div>
<?php else: ?>
    <?php $rows = db()->query('SELECT * FROM doc_templates ORDER BY id')->fetchAll(); ?>
    <div class="card">
        <h2>Системные шаблоны</h2>
        <?php if ($rows === []): ?>
            <p class="empty">Шаблоны не найдены. Импортируйте sql/schema_and_data.sql.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Код</th><th>Заголовок</th><th>Обновлён</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['code']) ?></td>
                            <td><?= e($row['title']) ?></td>
                            <td><?= e(fmt_dt($row['updated_at'])) ?></td>
                            <td><a class="btn btn-small btn-secondary" href="/admin/templates.php?edit=<?= e($row['code']) ?>">Изменить</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="muted">Состав шаблонов фиксирован (три печатные формы по ТЗ); администратор управляет их текстом.</p>
    </div>
<?php endif; ?>
<?php
layout_footer();