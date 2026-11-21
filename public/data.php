<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_role(['operator', 'admin']);

const IMPORT_MAX_BYTES = 2097152;
const IMPORT_MAX_RECORDS = 500;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    if ($action === 'export') {
        $data = [
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
        ];
        log_action('export', null, null, null, [
            'format' => 'json',
            'manufacturers' => count($data['manufacturers']),
            'car_models' => count($data['car_models']),
            'modifications' => count($data['modifications']),
        ]);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="sra_export_' . date('Ymd_His') . '.json"');
        header('Content-Length: ' . strlen($json));
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    if ($action === 'import') {
        $raw = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ((int) $_FILES['file']['size'] > IMPORT_MAX_BYTES) {
                flash_add('error', 'Файл больше 2 МБ — импорт отклонён.');
                redirect('/data.php');
            }
            $raw = (string) file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $raw = (string) ($_POST['json'] ?? '');
        }
        if (trim($raw) === '') {
            flash_add('error', 'Передайте JSON-файл или вставьте JSON в текстовое поле.');
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
            flash_add('error', 'JSON должен быть объектом с ключами manufacturers, car_models, modifications.');
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
                continue;
            }
            if (count($records) > IMPORT_MAX_RECORDS) {
                $errors[] = "Раздел {$key}: не более " . IMPORT_MAX_RECORDS . ' записей за один импорт.';
            }
        }
        if ($errors !== []) {
            flash_add('error', implode("\n", $errors));
            redirect('/data.php');
        }

        $pdo = db();
        $pdo->beginTransaction();
        $counts = ['created' => 0, 'updated' => 0];
        try {
            // Порядок важен: сначала родители, затем дети. Связи — по естественным ключам.
            foreach ($sections['manufacturers'] as $i => $record) {
                $check = entity_validate('manufacturers', (array) $record);
                if ($check['errors'] !== []) {
                    throw new RuntimeException("manufacturers, запись №" . ($i + 1) . ': ' . implode(' ', $check['errors']));
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
                    throw new RuntimeException("car_models, запись №" . ($i + 1) . ': производитель не найден: ' . ($record['manufacturer'] ?? '—'));
                }
                $record['manufacturer_id'] = (int) $manufacturerId;
                $check = entity_validate('car_models', $record);
                if ($check['errors'] !== []) {
                    throw new RuntimeException("car_models, запись №" . ($i + 1) . ': ' . implode(' ', $check['errors']));
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
                    throw new RuntimeException("modifications, запись №" . ($i + 1) . ': модель не найдена: '
                        . ($record['manufacturer'] ?? '—') . ' / ' . ($record['model'] ?? '—'));
                }
                $record['car_model_id'] = (int) $modelId;
                $check = entity_validate('modifications', $record);
                if ($check['errors'] !== []) {
                    throw new RuntimeException("modifications, запись №" . ($i + 1) . ': ' . implode(' ', $check['errors']));
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
            log_action('import', null, null, null, ['format' => 'json'] + $counts);
            $pdo->commit();
            flash_add('success', "Импорт завершён: создано {$counts['created']}, обновлено {$counts['updated']} записей.");
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            flash_add('error', 'Импорт отменён, изменения откатаны: ' . $e->getMessage());
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

layout_header('Импорт и экспорт JSON');
?>
<h1>Импорт и экспорт данных (JSON)</h1>
<div class="grid-2">
    <div class="card">
        <h2>Экспорт</h2>
        <p class="muted">Выгружаются все производители, модели и модификации. Связи передаются естественными
            ключами (наименования), поэтому файл можно импортировать обратно.</p>
        <form method="post" action="/data.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export">
            <button class="btn" type="submit">Скачать JSON</button>
        </form>
    </div>
    <div class="card">
        <h2>Импорт</h2>
        <p class="muted">Существующие записи обновляются, новые создаются. Импорт выполняется одной транзакцией:
            при ошибке в любой записи откатываются все изменения. Лимит: 2 МБ, до 500 записей на раздел.</p>
        <form method="post" action="/data.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <div class="form-row">
                <label for="file">Файл JSON</label>
                <input type="file" id="file" name="file" accept=".json,application/json">
            </div>
            <div class="form-row">
                <label for="json">…или вставьте JSON</label>
                <textarea id="json" name="json" rows="8"></textarea>
            </div>
            <button class="btn" type="submit">Импортировать</button>
        </form>
    </div>
</div>
<div class="card">
    <h2>Формат файла</h2>
    <pre class="state-json">{
  "manufacturers": [
    {"name": "ПримерАвто", "country": "Россия", "founded_year": 2000, "status": "active"}
  ],
  "car_models": [
    {"manufacturer": "ПримерАвто", "name": "Модель X", "body_type": "седан",
     "production_start_year": 2020, "production_end_year": null, "status": "in_production"}
  ],
  "modifications": [
    {"manufacturer": "ПримерАвто", "model": "Модель X", "code": "X-16-MT",
     "engine_type": "бензин", "engine_volume_cm3": 1598, "power_hp": 110,
     "transmission": "механика", "drive_type": "передний",
     "year_start": 2020, "year_end": null, "status": "in_production"}
  ]
}</pre>
</div>
<?php
layout_footer();