<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/login.php');
}
csrf_check();
auth_logout();
session_start();
flash_add('info', 'Вы вышли из системы.');
redirect('/login.php');