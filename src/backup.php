<?php

declare(strict_types=1);

function db_tables(): array
{
    return db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
}

function db_snapshot(?array $tables = null): array
{
    $snapshot = [];
    foreach ($tables ?? db_tables() as $table) {
        if (preg_match('/^[A-Za-z0-9_]+$/', (string) $table) !== 1) {
            continue;
        }
        $snapshot[$table] = db()->query("SELECT * FROM `{$table}`")->fetchAll();
    }
    return $snapshot;
}

function db_safe_row(array $row): array
{
    $safe = [];
    foreach ($row as $column => $value) {
        if (preg_match('/^[A-Za-z0-9_]+$/', (string) $column) === 1) {
            $safe[$column] = $value;
        }
    }
    return $safe;
}

function db_restore_snapshot(array $snapshot): void
{
    $pdo = db();
    $existing = db_tables();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();
    try {
        foreach ($snapshot as $table => $rows) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $table) !== 1
                || !in_array($table, $existing, true) || !is_array($rows)) {
                continue;
            }
            if ($table === 'audit_log') {
                foreach ($rows as $row) {
                    $safe = db_safe_row((array) $row);
                    if ($safe === []) {
                        continue;
                    }
                    $place = implode(', ', array_fill(0, count($safe), '?'));
                    $pdo->prepare('INSERT IGNORE INTO `audit_log` (`' . implode('`, `', array_keys($safe)) . "`) VALUES ({$place})")
                        ->execute(array_values($safe));
                }
                continue;
            }
            $pdo->exec("DELETE FROM `{$table}`");
            if ($rows === []) {
                continue;
            }
            $first = db_safe_row((array) $rows[0]);
            if ($first === []) {
                continue;
            }
            $place = implode(', ', array_fill(0, count($first), '?'));
            $stmt = $pdo->prepare("INSERT INTO `{$table}` (`" . implode('`, `', array_keys($first)) . "`) VALUES ({$place})");
            foreach ($rows as $row) {
                $stmt->execute(array_values(db_safe_row((array) $row)));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function sql_strip_definer(string $sql): string
{
    return (string) preg_replace('/DEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i', '', $sql);
}

function sql_dump(): string
{
    $pdo = db();
    $out = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach (db_tables() as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        $out .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
        foreach (array_chunk($rows, 100) as $chunk) {
            $columns = '`' . implode('`, `', array_keys($chunk[0])) . '`';
            $values = [];
            foreach ($chunk as $row) {
                $cells = [];
                foreach ($row as $value) {
                    $cells[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                }
                $values[] = '(' . implode(', ', $cells) . ')';
            }
            $out .= "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n\n";
        }
    }
    foreach ($pdo->query('SHOW TRIGGERS')->fetchAll() as $trigger) {
        $name = $trigger['Trigger'];
        $create = $pdo->query("SHOW CREATE TRIGGER `{$name}`")->fetch()['SQL Original Statement'];
        $out .= "DROP TRIGGER IF EXISTS `{$name}`;\nDELIMITER \$\$\n" . sql_strip_definer($create) . "\$\$\nDELIMITER ;\n\n";
    }
    $routines = $pdo->query(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()'
    )->fetchAll();
    foreach ($routines as $routine) {
        $type = strtoupper((string) $routine['ROUTINE_TYPE']);
        if (!in_array($type, ['FUNCTION', 'PROCEDURE'], true)) {
            continue;
        }
        $name = $routine['ROUTINE_NAME'];
        $row = $pdo->query("SHOW CREATE {$type} `{$name}`")->fetch();
        $create = $row['Create Function'] ?? $row['Create Procedure'] ?? '';
        if ($create === '') {
            continue;
        }
        $out .= "DROP {$type} IF EXISTS `{$name}`;\nDELIMITER \$\$\n" . sql_strip_definer($create) . "\$\$\nDELIMITER ;\n\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
}

function sql_split(string $script): array
{
    $script = str_replace(["\r\n", "\r"], "\n", $script);
    $length = strlen($script);
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    $i = 0;
    while ($i < $length) {
        $char = $script[$i];
        if ($char === "\n") {
            if (preg_match('/^DELIMITER\s+(\S+)$/i', trim($buffer), $match) === 1) {
                $delimiter = $match[1];
                $buffer = '';
                $i++;
                continue;
            }
            $buffer .= $char;
            $i++;
            continue;
        }
        $next = $script[$i + 1] ?? '';
        if ($char === '#' || ($char === '-' && $next === '-' && in_array($script[$i + 2] ?? ' ', [' ', "\t", "\n"], true))) {
            $end = strpos($script, "\n", $i);
            $i = $end === false ? $length : $end;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $end = strpos($script, '*/', $i + 2);
            $i = $end === false ? $length : $end + 2;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            $i++;
            while ($i < $length) {
                $current = $script[$i];
                if ($current === '\\' && $quote !== '`') {
                    $buffer .= $current . ($script[$i + 1] ?? '');
                    $i += 2;
                    continue;
                }
                if ($current === $quote) {
                    if (($script[$i + 1] ?? '') === $quote) {
                        $buffer .= $current . $current;
                        $i += 2;
                        continue;
                    }
                    $buffer .= $current;
                    $i++;
                    break;
                }
                $buffer .= $current;
                $i++;
            }
            continue;
        }
        if (substr($script, $i, strlen($delimiter)) === $delimiter) {
            $sql = trim($buffer);
            if ($sql !== '') {
                $statements[] = $sql;
            }
            $buffer = '';
            $i += strlen($delimiter);
            continue;
        }
        $buffer .= $char;
        $i++;
    }
    $sql = trim($buffer);
    if ($sql !== '') {
        $statements[] = $sql;
    }
    return $statements;
}

function sql_import(string $script): array
{
    $statements = sql_split($script);
    if ($statements === []) {
        throw new RuntimeException('Файл не содержит команд SQL.');
    }
    $executed = 0;
    foreach ($statements as $sql) {
        if (preg_match('/^(SET|DROP|CREATE|INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|ALTER|START|BEGIN|COMMIT)\b/i', $sql) !== 1
            || preg_match('/\b(GLOBAL|OUTFILE|DUMPFILE|LOAD_FILE)\b/i', $sql) === 1) {
            throw new RuntimeException('Недопустимая команда: ' . mb_substr($sql, 0, 60));
        }
        db()->exec($sql);
        $executed++;
    }
    return ['statements' => $executed];
}