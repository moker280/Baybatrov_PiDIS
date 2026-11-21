<?php

declare(strict_types=1);

function log_action(
    string $action,
    ?string $entityType,
    ?int $entityId,
    ?array $before,
    ?array $after,
    ?int $actorId = null
): void {
    $actor = $actorId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, entity_type, entity_id, action, state_before, state_after, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $actor,
        $entityType,
        $entityId,
        $action,
        $before === null ? null : encrypt_field(json_encode($before, JSON_UNESCAPED_UNICODE)),
        $after === null ? null : encrypt_field(json_encode($after, JSON_UNESCAPED_UNICODE)),
        encrypt_field(client_ip()),
    ]);
}

function audit_action_label(string $action): string
{
    return [
        'login' => 'Вход',
        'logout' => 'Выход',
        'create' => 'Создание',
        'read' => 'Чтение',
        'update' => 'Обновление',
        'delete' => 'Удаление',
        'import' => 'Импорт',
        'export' => 'Экспорт',
        'restore' => 'Восстановление',
        'lock' => 'Блокировка',
        'unlock' => 'Разблокировка',
        'login_failed' => 'Неудачный вход',
        'code_failed' => 'Неверный код',
    ][$action] ?? $action;
}

function audit_state(?string $encrypted): ?array
{
    $json = decrypt_field($encrypted);
    if ($json === null) {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function audit_can_restore(array $row): bool
{
    return in_array($row['entity_type'], ['manufacturers', 'car_models', 'modifications', 'users'], true)
        && in_array($row['action'], ['update', 'delete'], true)
        && !empty($row['state_before']);
}

function audit_restore_columns(string $type): array
{
    return [
        'manufacturers' => ['id', 'name', 'country', 'founded_year', 'status'],
        'car_models' => ['id', 'manufacturer_id', 'name', 'body_type', 'production_start_year', 'production_end_year', 'status'],
        'modifications' => ['id', 'car_model_id', 'code', 'engine_type', 'engine_volume_cm3', 'power_hp',
            'transmission', 'drive_type', 'year_start', 'year_end', 'status'],
        'users' => ['id', 'login', 'password_hash', 'role', 'email', 'phone', 'created_at',
            'last_password_change', 'is_locked', 'lock_until', 'login_fail_count', 'code_fail_count'],
    ][$type] ?? [];
}

function audit_restore(array $row): ?string
{
    if (!audit_can_restore($row)) {
        return 'Эту запись журнала нельзя использовать для восстановления.';
    }
    $type = (string) $row['entity_type'];
    $id = (int) $row['entity_id'];
    $before = audit_state($row['state_before']);
    if ($before === null) {
        return 'Состояние «до» не читается (проверьте ключ шифрования).';
    }
    $columns = audit_restore_columns($type);
    $data = array_intersect_key($before, array_flip($columns));
    unset($data['id']);
    if ($data === []) {
        return 'Состояние «до» не содержит полей сущности.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$type}` WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch() ?: null;

        // Проверки целостности до записи: родительские FK и уникальные естественные ключи.
        if ($type === 'car_models' && isset($data['manufacturer_id'])) {
            $p = $pdo->prepare('SELECT id FROM manufacturers WHERE id = ?');
            $p->execute([$data['manufacturer_id']]);
            if (!$p->fetch()) {
                $pdo->rollBack();
                return 'Производитель для этой модели отсутствует. Сначала восстановите производителя.';
            }
        }
        if ($type === 'modifications' && isset($data['car_model_id'])) {
            $p = $pdo->prepare('SELECT id FROM car_models WHERE id = ?');
            $p->execute([$data['car_model_id']]);
            if (!$p->fetch()) {
                $pdo->rollBack();
                return 'Модель для этой модификации отсутствует. Сначала восстановите модель.';
            }
        }
        $uniqueChecks = [
            'manufacturers' => ['name = ?', [$data['name'] ?? '']],
            'car_models' => ['manufacturer_id = ? AND name = ?', [$data['manufacturer_id'] ?? 0, $data['name'] ?? '']],
            'modifications' => ['code = ?', [$data['code'] ?? '']],
            'users' => ['login = ?', [$data['login'] ?? '']],
        ];
        [$cond, $params] = $uniqueChecks[$type];
        $p = $pdo->prepare("SELECT id FROM `{$type}` WHERE {$cond} AND id <> ?");
        $p->execute([...$params, $id]);
        if ($p->fetch()) {
            $pdo->rollBack();
            return 'Уникальный ключ записи уже занят другой записью — восстановление невозможно без конфликта.';
        }

        if ($current === null) {
            // Запись удалена (или отсутствует): вставляем с прежним id.
            $cols = ['id', ...array_keys($data)];
            $vals = [$id, ...array_values($data)];
            $place = implode(', ', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `{$type}` (`" . implode('`, `', $cols) . "`) VALUES ({$place})";
            $pdo->prepare($sql)->execute($vals);
        } else {
            $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($data)));
            $pdo->prepare("UPDATE `{$type}` SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
        }
        log_action('restore', $type, $id, $current ?: null, $before);
        $pdo->commit();
        return null;
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            return 'Конфликт ограничений БД (уникальность или внешние ключи). Восстановление отменено.';
        }
        throw $e;
    }
}

function audit_is_import_undo(array $row): bool
{
    return $row['action'] === 'import' && !empty($row['state_before']);
}

function audit_undo_import(array $row): ?string
{
    if (!audit_is_import_undo($row)) {
        return 'Для этой записи нет снимка состояния.';
    }
    $snapshot = audit_state($row['state_before']);
    if (!is_array($snapshot) || $snapshot === []) {
        return 'Снимок состояния не читается.';
    }
    try {
        db_restore_snapshot($snapshot);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return 'Конфликт ограничений БД при откате импорта.';
        }
        throw $e;
    }
    log_action('restore', null, null, null, ['undo_import' => (int) $row['id']]);
    return null;
}