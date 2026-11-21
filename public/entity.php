<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();
$type = get_str('type');
if (!in_array($type, entity_types(), true)) {
    http_response_code(404);
    layout_header('Раздел не найден');
    echo '<div class="card"><h1>404</h1><p>Неизвестный раздел справочника.</p></div>';
    layout_footer();
    exit;
}
$schema = entity_schema($type);
$canEdit = in_array($user['role'], ['operator', 'admin'], true);
$listUrl = '/entity.php?type=' . urlencode($type);
$errors = [];
$formData = null;
$editId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('Изменение справочника доступно только оператору и администратору.');
    }
    $action = post_str('action');
    $id = (int) post_str('id');

    if ($action === 'save') {
        $editId = $id;
        $existing = $id > 0 ? entity_find($type, $id) : null;
        if ($id > 0 && !$existing) {
            flash_add('error', 'Запись не найдена (возможно, уже удалена).');
            redirect($listUrl);
        }
        $check = entity_validate($type, $_POST);
        $errors = $check['errors'];
        $formData = $check['data'];
        if ($errors === []) {
            $unique = entity_unique_error($type, $formData, $existing ? $id : null);
            if ($unique !== null) {
                $errors['unique'] = $unique;
            }
        }
        if ($errors === []) {
            try {
                if ($existing) {
                    entity_update($type, $id, $formData);
                    log_action('update', $type, $id, $existing, entity_find($type, $id));
                    flash_add('success', 'Запись обновлена.');
                } else {
                    $newId = entity_insert($type, $formData);
                    log_action('create', $type, $newId, null, entity_find($type, $newId));
                    flash_add('success', 'Запись добавлена.');
                }
                redirect($listUrl);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors['unique'] = 'Конфликт ограничений БД: проверьте уникальность и связанные записи.';
                } else {
                    throw $e;
                }
            }
        }
    } elseif ($action === 'delete') {
        $row = entity_find($type, $id);
        if (!$row) {
            flash_add('error', 'Запись не найдена.');
            redirect($listUrl);
        }
        $error = entity_delete($type, $id);
        if ($error !== null) {
            flash_add('error', $error);
        } else {
            log_action('delete', $type, $id, $row, null);
            flash_add('success', 'Запись удалена. Восстановление возможно через журнал (администратор).');
        }
        redirect($listUrl);
    } elseif ($action === 'status') {
        $before = entity_find($type, $id);
        if (!$before) {
            flash_add('error', 'Запись не найдена.');
            redirect($listUrl);
        }
        $error = entity_change_status($type, $id, post_str('status'));
        if ($error !== null) {
            flash_add('error', $error);
        } else {
            log_action('update', $type, $id, $before, entity_find($type, $id));
            flash_add('success', 'Статус изменён.');
        }
        redirect($listUrl);
    } else {
        redirect($listUrl);
    }
}

// Режим формы: создание (?new=1) или редактирование (?edit=N)
$showForm = $formData !== null;
if (!$showForm && $canEdit) {
    if (get_str('new') === '1') {
        $showForm = true;
        $formData = [];
        foreach ($schema['fields'] as $name => $def) {
            $formData[$name] = $def['default'] ?? ($def['type'] === 'int' ? null : '');
        }
    } elseif (get_str('edit') !== '') {
        $editId = (int) get_str('edit');
        $row = entity_find($type, $editId);
        if ($row) {
            $showForm = true;
            $formData = $row;
        } else {
            flash_add('error', 'Запись не найдена.');
            redirect($listUrl);
        }
    }
}

layout_header($schema['title']);
?>
<h1><?= e($schema['title']) ?></h1>
<?php if ($showForm): ?>
    <div class="card">
        <h2><?= $editId > 0 ? 'Изменение записи №' . $editId : 'Добавление записи' ?></h2>
        <?php errors_block($errors) ?>
        <form method="post" action="<?= e($listUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editId ?>">
            <?php foreach ($schema['fields'] as $name => $def): ?>
                <?php
                $options = [];
                if ($def['type'] === 'fk') {
                    $options = entity_options($def['source']);
                } elseif ($def['type'] === 'select') {
                    $options = $def['options'];
                }
                field_html($def, $name, $formData[$name] ?? null, $options);
                ?>
            <?php endforeach; ?>
            <div class="form-actions">
                <button class="btn" type="submit">Сохранить</button>
                <a class="btn-link" href="<?= e($listUrl) ?>">Отмена</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <?php if ($canEdit): ?>
        <p><a class="btn" href="<?= e($listUrl) ?>&amp;new=1">Добавить <?= e($schema['one']) ?></a></p>
    <?php endif; ?>
    <div class="card">
        <h2>Фильтры</h2>
        <form method="get" action="/entity.php" class="filters">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <?php foreach ($schema['filters'] as $f): ?>
                <div class="form-row">
                    <label for="flt_<?= e($f['param']) ?>"><?= e($f['label']) ?></label>
                    <?php if (isset($f['options']) || isset($f['fk']) || isset($f['field'])): ?>
                        <?php
                        $opts = $f['options'] ?? (isset($f['fk']) ? entity_options($f['fk']) : $schema['fields'][$f['field']]['options']);
                        ?>
                        <select id="flt_<?= e($f['param']) ?>" name="<?= e($f['param']) ?>">
                            <option value="">Все</option>
                            <?php foreach ($opts as $key => $label): ?>
                                <option value="<?= e((string) $key) ?>"<?= get_str($f['param']) === (string) $key ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="<?= !empty($f['int']) ? 'number' : 'text' ?>" id="flt_<?= e($f['param']) ?>"
                               name="<?= e($f['param']) ?>" value="<?= e(get_str($f['param'])) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="form-actions">
                <button class="btn" type="submit">Применить</button>
                <a class="btn-link" href="<?= e($listUrl) ?>">Сбросить</a>
            </div>
        </form>
    </div>
    <?php
    $rows = entity_list($type, $_GET);
    $sortCurrent = get_str('sort');
    $dirCurrent = get_str('dir') === 'desc' ? 'desc' : 'asc';
    ?>
    <div class="card">
        <h2>Записи (<?= count($rows) ?><?= count($rows) >= 300 ? ', показаны первые 300' : '' ?>)</h2>
        <?php if ($rows === []): ?>
            <p class="empty">Записей нет. <?= $canEdit ? 'Добавьте первую запись или измените фильтры.' : 'Измените фильтры.' ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="list">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <?php foreach ($schema['columns'] as $col => $label): ?>
                            <th>
                                <?php if (isset($schema['sortable'][$col])): ?>
                                    <?php
                                    $nextDir = ($sortCurrent === $col && $dirCurrent === 'asc') ? 'desc' : 'asc';
                                    $sortUrl = e($listUrl . '&' . http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $nextDir])));
                                    ?>
                                    <a class="sort-link" href="<?= $sortUrl ?>"><?= e($label) ?><?= $sortCurrent === $col ? ($dirCurrent === 'asc' ? ' ▲' : ' ▼') : '' ?></a>
                                <?php else: ?>
                                    <?= e($label) ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        <?php if ($canEdit || $type === 'car_models'): ?><th>Действия</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <?php foreach ($schema['columns'] as $col => $label): ?>
                                <td>
                                    <?php if ($col === 'status'): ?>
                                        <?php status_badge((string) $row['status']); ?>
                                    <?php else: ?>
                                        <?= e((string) ($row[$col] ?? '—')) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($canEdit || $type === 'car_models'): ?>
                                <td class="actions">
                                    <?php if ($type === 'car_models'): ?>
                                        <a class="btn btn-small btn-secondary" href="/model_card.php?id=<?= (int) $row['id'] ?>">Карточка</a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <a class="btn btn-small btn-secondary" href="<?= e($listUrl) ?>&amp;edit=<?= (int) $row['id'] ?>">Изменить</a>
                                        <?php $targets = $schema['transitions'][$row['status']] ?? []; ?>
                                        <?php if ($targets !== []): ?>
                                            <form method="post" action="<?= e($listUrl) ?>" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <select name="status">
                                                    <?php foreach ($targets as $target): ?>
                                                        <option value="<?= e($target) ?>"><?= e(status_label($target)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-small" type="submit">Сменить статус</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e($listUrl) ?>" class="inline-form"
                                              data-confirm="Удалить запись №<?= (int) $row['id'] ?>? Действие фиксируется в журнале.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button class="btn btn-small btn-danger" type="submit">Удалить</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
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