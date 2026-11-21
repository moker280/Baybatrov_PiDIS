<?php

declare(strict_types=1);

function entity_types(): array
{
    static $schemas = null;
    if ($schemas === null) {
        $schemas = require __DIR__ . '/entities.php';
    }
    return array_keys($schemas);
}

function entity_schema(string $type): array
{
    static $schemas = null;
    if ($schemas === null) {
        $schemas = require __DIR__ . '/entities.php';
    }
    if (!isset($schemas[$type])) {
        throw new RuntimeException('Неизвестный тип сущности.');
    }
    return $schemas[$type];
}

function entity_options(string $source): array
{
    if ($source === 'manufacturers') {
        return db()->query('SELECT id, name FROM manufacturers ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return db()->query(
        'SELECT c.id, CONCAT(mf.name, \' \', c.name) AS label
         FROM car_models c JOIN manufacturers mf ON mf.id = c.manufacturer_id
         ORDER BY mf.name, c.name'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}

function entity_find(string $type, int $id): ?array
{
    entity_schema($type); // белый список имени таблицы
    $stmt = db()->prepare("SELECT * FROM `{$type}` WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function entity_validate(string $type, array $in): array
{
    $s = entity_schema($type);
    $errors = [];
    $data = [];
    foreach ($s['fields'] as $name => $def) {
        $raw = trim((string) ($in[$name] ?? ''));
        $label = $def['label'];
        if ($def['type'] === 'int') {
            if ($raw === '') {
                if (!empty($def['required'])) {
                    $errors[$name] = "Заполните поле «{$label}».";
                } else {
                    $data[$name] = null;
                }
                continue;
            }
            if (preg_match('/^\d{1,6}$/', $raw) !== 1) {
                $errors[$name] = "Поле «{$label}» должно быть целым числом.";
                continue;
            }
            $v = (int) $raw;
            if ($v < $def['min'] || $v > $def['max']) {
                $errors[$name] = "Поле «{$label}»: допустимо от {$def['min']} до {$def['max']}.";
                continue;
            }
            $data[$name] = $v;
        } elseif ($def['type'] === 'select' || $def['type'] === 'fk') {
            $options = $def['type'] === 'fk' ? entity_options($def['source']) : $def['options'];
            if ($raw === '') {
                if (!empty($def['required'])) {
                    $errors[$name] = "Выберите значение поля «{$label}».";
                } else {
                    $data[$name] = null;
                }
                continue;
            }
            $key = $def['type'] === 'fk' ? (int) $raw : $raw;
            if (!isset($options[$key])) {
                $errors[$name] = "Недопустимое значение поля «{$label}».";
                continue;
            }
            $data[$name] = $key;
        } else {
            if ($raw === '' && !empty($def['required'])) {
                $errors[$name] = "Заполните поле «{$label}».";
                continue;
            }
            if (mb_strlen($raw) > (int) $def['max']) {
                $errors[$name] = "Поле «{$label}»: не более {$def['max']} символов.";
                continue;
            }
            $data[$name] = $raw;
        }
    }
    if ($type === 'car_models') {
        $start = $data['production_start_year'] ?? null;
        $end = $data['production_end_year'] ?? null;
        if ($start !== null && $end !== null && $end < $start) {
            $errors['production_end_year'] = 'Год окончания выпуска меньше года начала.';
        }
        if ($end !== null && ($data['status'] ?? '') === 'in_production') {
            $errors['status'] = 'Указан год окончания — выберите статус «Снят с производства» или очистите год.';
        }
    }
    if ($type === 'modifications') {
        $start = $data['year_start'] ?? null;
        $end = $data['year_end'] ?? null;
        if ($start !== null && $end !== null && $end < $start) {
            $errors['year_end'] = 'Год окончания выпуска меньше года начала.';
        }
        if ($end !== null && ($data['status'] ?? '') === 'in_production') {
            $errors['status'] = 'Указан год окончания — выберите статус «Снят с производства» или очистите год.';
        }
        if (($data['engine_type'] ?? '') === 'электро') {
            if (($data['engine_volume_cm3'] ?? null) !== null) {
                $errors['engine_volume_cm3'] = 'Для электродвигателя объём не указывается.';
            }
            $data['engine_volume_cm3'] = null;
        } elseif (($data['engine_volume_cm3'] ?? null) === null && !isset($errors['engine_volume_cm3'])) {
            $errors['engine_volume_cm3'] = 'Укажите объём двигателя (для электро — оставьте пустым).';
        }
    }
    return ['errors' => $errors, 'data' => $data];
}

function entity_unique_error(string $type, array $data, ?int $excludeId = null): ?string
{
    $checks = [
        'manufacturers' => ['name = ?', [$data['name']], 'Производитель с таким наименованием уже существует.'],
        'car_models' => ['manufacturer_id = ? AND name = ?', [$data['manufacturer_id'], $data['name']],
            'У этого производителя уже есть модель с таким наименованием.'],
        'modifications' => ['code = ?', [$data['code']], 'Модификация с таким кодом уже существует.'],
    ];
    [$cond, $params, $message] = $checks[$type];
    $sql = "SELECT id FROM `{$type}` WHERE {$cond}";
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ? $message : null;
}

function entity_insert(string $type, array $data): int
{
    $cols = array_keys(entity_schema($type)['fields']);
    $place = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `{$type}` (`" . implode('`, `', $cols) . "`) VALUES ({$place})";
    db()->prepare($sql)->execute(array_map(static fn (string $c) => $data[$c] ?? null, $cols));
    return (int) db()->lastInsertId();
}

function entity_update(string $type, int $id, array $data): void
{
    $cols = array_keys(entity_schema($type)['fields']);
    $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", $cols));
    db()->prepare("UPDATE `{$type}` SET {$set} WHERE id = ?")
        ->execute([...array_map(static fn (string $c) => $data[$c] ?? null, $cols), $id]);
}

function entity_delete(string $type, int $id): ?string
{
    entity_schema($type);
    try {
        db()->prepare("DELETE FROM `{$type}` WHERE id = ?")->execute([$id]);
        return null;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return 'Нельзя удалить запись: существуют связанные записи. Сначала удалите или перенесите их.';
        }
        throw $e;
    }
}

function entity_change_status(string $type, int $id, string $target): ?string
{
    $s = entity_schema($type);
    $row = entity_find($type, $id);
    if (!$row) {
        return 'Запись не найдена.';
    }
    $allowed = $s['transitions'][$row['status']] ?? [];
    if (!in_array($target, $allowed, true)) {
        return 'Недопустимый переход статуса для этой записи.';
    }
    if ($type === 'manufacturers') {
        db()->prepare('UPDATE manufacturers SET status = ? WHERE id = ?')->execute([$target, $id]);
        return null;
    }
    // Законченная транзакция «изменение статуса производства»: статус согласуется с годом окончания.
    if ($type === 'car_models') {
        $end = $target === 'discontinued'
            ? ($row['production_end_year'] ?? max((int) date('Y'), (int) $row['production_start_year']))
            : null;
        db()->prepare('UPDATE car_models SET status = ?, production_end_year = ? WHERE id = ?')
            ->execute([$target, $end, $id]);
        return null;
    }
    $end = $target === 'discontinued'
        ? ($row['year_end'] ?? max((int) date('Y'), (int) $row['year_start']))
        : null;
    db()->prepare('UPDATE modifications SET status = ?, year_end = ? WHERE id = ?')->execute([$target, $end, $id]);
    return null;
}

function entity_list(string $type, array $get): array
{
    $s = entity_schema($type);
    $where = [];
    $params = [];
    foreach ($s['filters'] as $f) {
        $raw = trim((string) ($get[$f['param']] ?? ''));
        if ($raw === '') {
            continue;
        }
        if ($f['kind'] === 'like') {
            $parts = [];
            foreach ($f['cols'] as $col) {
                $parts[] = $col . ' LIKE ?';
                $params[] = '%' . $raw . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        } elseif ($f['kind'] === 'eq') {
            $where[] = $f['col'] . ' = ?';
            $params[] = !empty($f['int']) ? (int) $raw : $raw;
        } elseif ($f['kind'] === 'gte') {
            $where[] = $f['col'] . ' >= ?';
            $params[] = (int) $raw;
        } elseif ($f['kind'] === 'lte') {
            $where[] = $f['col'] . ' <= ?';
            $params[] = (int) $raw;
        }
    }
    // Сортировка — только по белому списку столбцов и направлений.
    $sortExpr = $s['sortable'][(string) ($get['sort'] ?? '')] ?? $s['default_order'];
    $dir = strtolower((string) ($get['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $sql = $s['list_sql']
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY ' . $sortExpr . ' ' . $dir . ' LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}