<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Скрипт выполняется только из командной строки: php scripts/seed_users.php');
}

require dirname(__DIR__) . '/src/bootstrap.php';

// Пароли предназначены ТОЛЬКО для локальной демонстрации и соответствуют политике проекта.
$accounts = [
    ['login' => 'admin', 'password' => 'Admin#2026', 'role' => 'admin',
        'email' => 'admin@example.test', 'phone' => '+7 (900) 000-00-01'],
    ['login' => 'operator', 'password' => 'Smena#2026', 'role' => 'operator',
        'email' => 'operator@example.test', 'phone' => '+7 (900) 000-00-02'],
    ['login' => 'user', 'password' => 'User#2026', 'role' => 'user',
        'email' => 'user@example.test', 'phone' => null],
];

echo "АИС «СРА»: создание тестовых учётных записей\n";

foreach ($accounts as $account) {
    $policyErrors = password_policy_errors($account['password']);
    if ($policyErrors !== []) {
        fwrite(STDERR, "Пароль для «{$account['login']}» не соответствует политике: "
            . implode(' ', $policyErrors) . "\n");
        exit(1);
    }
    $stmt = db()->prepare('SELECT id, role FROM users WHERE login = ?');
    $stmt->execute([$account['login']]);
    $existing = $stmt->fetch();
    if ($existing) {
        echo "[пропуск] Логин «{$account['login']}» уже занят (роль: {$existing['role']}). "
            . "Пароль и роль существующей записи не изменяются.\n";
        continue;
    }
    $now = date('Y-m-d H:i:s');
    // Требование: хэш формируется при выполнении скрипта через password_hash(..., PASSWORD_DEFAULT).
    $hash = password_hash($account['password'], PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        'INSERT INTO users (login, password_hash, role, email, phone, created_at, last_password_change,
                            is_locked, lock_until, login_fail_count, code_fail_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, 0, 0)'
    );
    $stmt->execute([
        $account['login'],
        $hash,
        $account['role'],
        encrypt_field($account['email']),
        $account['phone'] === null ? null : encrypt_field($account['phone']),
        $now,
        $now,
    ]);
    $id = (int) db()->lastInsertId();
    log_action('create', 'users', $id, null, [
        'login' => $account['login'],
        'role' => $account['role'],
        'source' => 'seed_users.php',
    ], $id);
    echo "[создан] {$account['login']} (роль: {$account['role']}, id: {$id})\n";
}

echo "Готово. Эти пароли предназначены только для локальной демонстрации.\n";