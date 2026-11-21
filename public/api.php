<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_send(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        api_send(400, ['error' => 'Некорректный JSON: ' . $e->getMessage()]);
    }
    if (!is_array($data)) {
        api_send(400, ['error' => 'Тело запроса должно быть JSON-объектом.']);
    }
    return $data;
}

try {
    $token = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string) cfg('security.api_token'), $token)) {
        api_send(401, ['error' => 'Неверный или отсутствующий заголовок X-API-Token.']);
    }

    $path = trim((string) ($_SERVER['PATH_INFO'] ?? ($_GET['r'] ?? '')), '/');
    $segments = $path === '' ? [] : explode('/', $path);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $resource = $segments[0] ?? '';

    if ($method === 'GET' && $resource === 'manufacturers') {
        api_send(200, db()->query(
            'SELECT m.*, fn_manufacturer_models_count(m.id) AS models_count FROM manufacturers m ORDER BY m.name'
        )->fetchAll());
    }
    if ($method === 'GET' && $resource === 'car_models') {
        api_send(200, db()->query(
            'SELECT c.*, mf.name AS manufacturer_name FROM car_models c
             JOIN manufacturers mf ON mf.id = c.manufacturer_id ORDER BY mf.name, c.name'
        )->fetchAll());
    }
    if ($method === 'GET' && $resource === 'modifications') {
        api_send(200, db()->query(
            'SELECT md.*, c.name AS model_name, mf.name AS manufacturer_name FROM modifications md
             JOIN car_models c ON c.id = md.car_model_id
             JOIN manufacturers mf ON mf.id = c.manufacturer_id ORDER BY md.code'
        )->fetchAll());
    }
    if ($method === 'GET' && $resource === 'models' && isset($segments[1])) {
        $id = (int) $segments[1];
        $stmt = db()->prepare(
            'SELECT c.*, mf.name AS manufacturer_name, fn_model_production_years(c.id) AS production_years
             FROM car_models c JOIN manufacturers mf ON mf.id = c.manufacturer_id WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $model = $stmt->fetch();
        if (!$model) {
            api_send(404, ['error' => 'Модель не найдена.']);
        }
        $stmt = db()->prepare('SELECT * FROM modifications WHERE car_model_id = ? ORDER BY code');
        $stmt->execute([$id]);
        $model['modifications'] = $stmt->fetchAll();
        log_action('read', 'car_models', $id, null, ['view' => 'api']);
        api_send(200, $model);
    }
    if ($method === 'GET' && $resource === 'report' && ($segments[1] ?? '') === 'summary') {
        api_send(200, call_procedure('CALL sp_manufacturer_report()'));
    }
    if ($resource === 'manufacturers' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        // Записывающие операции API: CSRF не применяется (нет cookie-сессии), авторизация — по токену.
        if ($method === 'POST') {
            $check = entity_validate('manufacturers', api_body());
            if ($check['errors'] !== []) {
                api_send(422, ['error' => 'Ошибка валидации', 'fields' => $check['errors']]);
            }
            $unique = entity_unique_error('manufacturers', $check['data']);
            if ($unique !== null) {
                api_send(409, ['error' => $unique]);
            }
            $id = entity_insert('manufacturers', $check['data']);
            log_action('create', 'manufacturers', $id, null, entity_find('manufacturers', $id));
            api_send(201, ['id' => $id]);
        }
        if ($method === 'PUT') {
            $body = api_body();
            $id = (int) ($body['id'] ?? 0);
            $existing = $id > 0 ? entity_find('manufacturers', $id) : null;
            if (!$existing) {
                api_send(404, ['error' => 'Производитель не найден (передайте id).']);
            }
            $check = entity_validate('manufacturers', $body);
            if ($check['errors'] !== []) {
                api_send(422, ['error' => 'Ошибка валидации', 'fields' => $check['errors']]);
            }
            $unique = entity_unique_error('manufacturers', $check['data'], $id);
            if ($unique !== null) {
                api_send(409, ['error' => $unique]);
            }
            entity_update('manufacturers', $id, $check['data']);
            log_action('update', 'manufacturers', $id, $existing, entity_find('manufacturers', $id));
            api_send(200, ['updated' => $id]);
        }
        $id = (int) get_str('id');
        $existing = $id > 0 ? entity_find('manufacturers', $id) : null;
        if (!$existing) {
            api_send(404, ['error' => 'Производитель не найден (передайте ?id=).']);
        }
        $error = entity_delete('manufacturers', $id);
        if ($error !== null) {
            api_send(409, ['error' => $error]);
        }
        log_action('delete', 'manufacturers', $id, $existing, null);
        api_send(200, ['deleted' => $id]);
    }
    api_send(404, ['error' => 'Ресурс не найден. Доступно: manufacturers (GET/POST/PUT/DELETE), '
        . 'car_models (GET), modifications (GET), models/{id} (GET), report/summary (GET).']);
} catch (DbUnavailableException $e) {
    api_send(503, ['error' => 'База данных недоступна.']);
} catch (Throwable $e) {
    app_log_write('API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_send(500, ['error' => 'Внутренняя ошибка сервера.']);
}