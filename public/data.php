<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_role(['operator', 'admin']);
$isAdmin = $user['role'] === 'admin';

const IMPORT_MAX_BYTES = 16777216;
const IMPORT_MAX_RECORDS = 500;
const SNAPSHOT_MAX_BYTES = 15000000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    if ($action === 'export') {
        $format = post_str('format') === 'sql' ? 'sql' : 'json';
        if ($format === 'sql') {
            $content = sql_dump();
            $mime = 'application/sql; charset=utf-8';
        } else {
            $content = json_encode([
                'manufacturers' => db()->query('SELECT name, country, founded_year, status FROM manufacturers ORDER BY name')->fetchAll(),
                'car_models' => db()->query(
                    'SELECT mf.name AS manufacturer, c.name, c.body_type, c.production_start_year, c.production_end_year, c.status
                     FROM car_models c JOIN manufacturers mf ON mf.id = c.manufacturer_id ORDER BY mf.name, c.name'
                )->fetchAll(),
                'modifications' => db()->query(
                    'SELECT mf.name AS manufacturer, c.name AS model, md.code, md.engine_type, md.engine_volume_cm3,
                            md.power_hp, md.transmission, md.drive_type, md.year_start, md.year_end, md.status
                     FROM modifications md
                     JOIN car_models c ON c.id = md.car_model_id
                     JOIN manufacturers mf ON mf.id = c.manufacturer_id ORDER BY md.code'
                )->fetchAll(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mime = 'application/json; charset=utf-8';
        }
        log_action('export', null, null, null, ['format' => $format]);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="sra_export_' . date('Ymd_His') . '.' . $format . '"');
        header('Content-Length: ' . strlen($content));
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }

    if ($action === 'import_sql') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Импорт SQL доступен только администратору.');
        }
        if (empty($_FILES['sql_file']['tmp_name']) || !is_uploaded_file($_FILES['sql_file']['tmp_name'])) {
            flash_add('error', 'Выберите файл SQL.');
            redirect('/data.php');
        }
        if ((int) $_FILES['sql_file']['size'] > IMPORT_MAX_BYTES) {
            flash_add('error', 'Файл больше 16 МБ.');
            redirect('/data.php');
        }
        $script = (string) file_get_contents($_FILES['sql_file']['tmp_name']);
        $snapshot = db_snapshot();
        $encoded = encrypt_field(json_encode($snapshot, JSON_UNESCAPED_UNICODE));
        if (strlen((string) $encoded) > SNAPSHOT_MAX_BYTES) {
            flash_add('error', 'База слишком большая для отката через журнал.');
            redirect('/data.php');
        }
        try {
            $result = sql_import($script);
            log_action('import', null, null, $snapshot, ['format' => 'sql', 'statements' => $result['statements']]);
            flash_add('success', 'Импорт SQL выполнен: ' . $result['statements'] . ' команд.');
        } catch (Throwable $e) {
            app_log_write('SQL import: ' . $e->getMessage());
            log_action('import', null, null, $snapshot, ['format' => 'sql', 'error' => $e->getMessage()]);
            flash_add('error', 'Импорт SQL прерван. Откат доступен через журнал.');
        }
        redirect('/data.php');
    }

    if ($action === 'import_json') {
        $raw = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ((int) $_FILES['file']['size'] > IMPORT_MAX_BYTES) {
                flash_add('error', 'Файл больше 16 МБ.');
                redirect('/data.php');
            }
            $raw = (string) file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $raw = (string) ($_POST['json'] ?? '');
        }
        if (trim($raw) === '') {
            flash_add('error', 'Передайте файл или текст JSON.');
            redirect('/data.php');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            app_log_write('Import JSON: ' . $e->getMessage());
            flash_add('error', 'Некорректный JSON: ' . $e->getMessage());
            redirect('/data.php');
        }
        if (!is_array($data)) {
            flash_add('error', 'Ожидается объект с ключами manufacturers, car_models, modifications.');
            redirect('/data.php');
        }
        $sections = [
            'manufacturers' => $data['manufacturers'] ?? [],
            'car_models' => $data['car_models'] ?? [],
            'modifications' => $data['modifications'] ?? [],
        ];
        $errors = [];
        foreach ($sections as $key => $records) {
            if (!is_array($records)) {
                $errors[] = "Раздел {$key} должен быть массивом.";
            } elseif (count($records) > IMPORT_MAX_RECORDS) {
                $errors[] = "Раздел {$key}: не более " . IMPORT_MAX_RECORDS . ' записей.';
            }
        }
        if ($errors !== []) {
            flash_add('error', implode("\n", $errors));
            redirect('/data.php');
        }

        $snapshot = db_snapshot(['manufacturers', 'car_models', 'modifications']);
        $pdo = db();
        $pdo->beginTransaction();
        $counts = ['created' => 0, 'updated' => 0];
        try {
            foreach ($sections['manufacturers'] as $i => $record) {
                $check = entity_validate('manufacturers', (array) $record);
                if ($check['errors'] !== []) {
                    throw new RuntimeException('manufacturers №' . ($i + 1) . ': ' . implode(' ', $check['errors']));
                }
                $stmt = $pdo->prepare('SELECT id FROM manufacturers WHERE name = ?');
                $stmt->execute([$check['data']['name']]);
                $existingId = $stmt->fetchColumn();
                if ($existingId !== false) {
                    entity_update('manufacturers', (int) $existingId, $check['data']);
                    $counts['updated']++;
                } else {
                    entity_insert('manufacturers', $check['data']);
                    $counts['created']++;
                }
            }
            foreach ($sections['car_models'] as $i => $record) {
                $record = (array) $record;
                $stmt = $pdo->prepare('SELECT id FROM manufacturers WHERE name = ?');
                $stmt->execute([trim((string) ($record['manufacturer'] ?? ''))]);
                $manufacturerId = $stmt->fetchColumn();
                if ($manufacturerId === false) {
                    throw new RuntimeException('car_models №' . ($i + 1) . ': производитель не найден.');
                }
                $record['manufacturer_id'] = (int) $manufacturerId;
                $check = entity_validate('car_models', $record);
                if ($check['errors'] !== []) {
                    throw new RuntimeException('car_models №' . ($i + 1) . ': ' . implode(' ', $check['errors']));
                }
                $stmt = $pdo->prepare('SELECT id FROM car_models WHERE manufacturer_id = ? AND name = ?');
                $stmt->execute([$check['data']['manufacturer_id'], $check['data']['name']]);
                $existingId = $stmt->fetchColumn();
                if ($existingId !== false) {
                    entity_update('car_models', (int) $existingId, $check['data']);
                    $counts['updated']++;
                } else {
                    entity_insert('car_models', $check['data']);
                    $counts['created']++;
                }
            }
            foreach ($sections['modifications'] as $i => $record) {
                $record = (array) $record;
                $stmt = $pdo->prepare(
                    'SELECT c.id FROM car_models c JOIN manufacturers mf ON mf.id = c.manufacturer_id
                     WHERE mf.name = ? AND c.name = ?'
                );
                $stmt->execute([trim((string) ($record['manufacturer'] ?? '')), trim((string) ($record['model'] ?? ''))]);
                $modelId = $stmt->fetchColumn();
                if ($modelId === false) {
                    throw new RuntimeException('modifications №' . ($i + 1) . ': модель не найдена.');
                }
                $record['car_model_id'] = (int) $modelId;
                $check = entity_validate('modifications', $record);
                if ($check['errors'] !== []) {
                    throw new RuntimeException('modifications №' . ($i + 1) . ': ' . implode(' ', $check['errors']));
                }
                $stmt = $pdo->prepare('SELECT id FROM modifications WHERE code = ?');
                $stmt->execute([$check['data']['code']]);
                $existingId = $stmt->fetchColumn();
                if ($existingId !== false) {
                    entity_update('modifications', (int) $existingId, $check['data']);
                    $counts['updated']++;
                } else {
                    entity_insert('modifications', $check['data']);
                    $counts['created']++;
                }
            }
            log_action('import', null, null, $snapshot, ['format' => 'json'] + $counts);
            $pdo->commit();
            flash_add('success', "Импорт JSON: создано {$counts['created']}, обновлено {$counts['updated']}.");
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            flash_add('error', 'Импорт отменён: ' . $e->getMessage());
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                flash_add('error', 'Импорт отменён: конфликт уникальности или внешних ключей.');
            } else {
                throw $e;
            }
        }
        redirect('/data.php');
    }
    redirect('/data.php');
}

layout_header('Импорт и экспорт');
?>
<h1>Импорт и экспорт</h1>
<div class="grid-2">
    <div class="card">
        <h2>Экспорт</h2>
        <form method="post" action="/data.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export">
            <div class="form-actions">
                <button class="btn" type="submit" name="format" value="json">JSON</button>
                <button class="btn" type="submit" name="format" value="sql">SQL</button>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>Импорт JSON</h2>
        <form method="post" action="/data.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_json">
            <div class="form-row">
                <label for="file">Файл</label>
                <input type="file" id="file" name="file" accept=".json,application/json">
            </div>
            <div class="form-row">
                <label for="json">Текст JSON</label>
                <textarea id="json" name="json" rows="6"></textarea>
            </div>
            <button class="btn" type="submit">Импортировать</button>
        </form>
    </div>
    <?php if ($isAdmin): ?>
        <div class="card">
            <h2>Импорт SQL</h2>
            <form method="post" action="/data.php" enctype="multipart/form-data"
                  data-confirm="Импорт SQL заменит содержимое базы. Продолжить?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_sql">
                <div class="form-row">
                    <label for="sql_file">Файл</label>
                    <input type="file" id="sql_file" name="sql_file" accept=".sql,text/plain" required>
                </div>
                <button class="btn" type="submit">Импортировать</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php
layout_footer();