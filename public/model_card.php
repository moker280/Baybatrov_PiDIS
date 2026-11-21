<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();
$id = (int) get_str('id');
$stmt = db()->prepare(
    'SELECT c.*, mf.name AS manufacturer_name, mf.country, mf.founded_year,
            fn_model_production_years(c.id) AS production_years
     FROM car_models c JOIN manufacturers mf ON mf.id = c.manufacturer_id WHERE c.id = ?'
);
$stmt->execute([$id]);
$model = $stmt->fetch();
if (!$model) {
    http_response_code(404);
    layout_header('Модель не найдена');
    echo '<div class="card"><h1>404</h1><p>Модель не найдена.</p><p><a class="btn" href="/entity.php?type=car_models">К списку моделей</a></p></div>';
    layout_footer();
    exit;
}
$stmt = db()->prepare('SELECT * FROM modifications WHERE car_model_id = ? ORDER BY code');
$stmt->execute([$id]);
$mods = $stmt->fetchAll();
log_action('read', 'car_models', $id, null, ['view' => 'model_card']);

layout_header('Карточка: ' . $model['name']);
?>
<h1><?= e($model['manufacturer_name']) ?> <?= e($model['name']) ?></h1>
<div class="card">
    <h2>Карточка автомобиля</h2>
    <table class="props">
        <tr><th>Производитель</th><td><?= e($model['manufacturer_name']) ?> (<?= e($model['country']) ?>, основан в <?= (int) $model['founded_year'] ?>)</td></tr>
        <tr><th>Модель</th><td><?= e($model['name']) ?></td></tr>
        <tr><th>Тип кузова</th><td><?= e($model['body_type']) ?></td></tr>
        <tr><th>Годы производства</th><td><?= e($model['production_years']) ?></td></tr>
        <tr><th>Статус</th><td><?php status_badge((string) $model['status']); ?></td></tr>
    </table>
    <div class="form-actions">
        <form method="post" action="/document.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="car_card">
            <input type="hidden" name="model_id" value="<?= $id ?>">
            <button class="btn" type="submit">Карточка в PDF</button>
        </form>
        <form method="post" action="/document.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="model_info">
            <input type="hidden" name="model_id" value="<?= $id ?>">
            <button class="btn btn-secondary" type="submit">Справка о модели (PDF)</button>
        </form>
        <a class="btn-link" href="/entity.php?type=car_models">К списку моделей</a>
    </div>
</div>
<div class="card">
    <h2>Модификации (<?= count($mods) ?>)</h2>
    <?php if ($mods === []): ?>
        <p class="empty">Для этой модели модификации ещё не добавлены.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="list">
                <thead>
                <tr><th>Код</th><th>Двигатель</th><th>Объём, см³</th><th>Л.с.</th><th>КПП</th><th>Привод</th><th>Годы</th><th>Статус</th></tr>
                </thead>
                <tbody>
                <?php foreach ($mods as $m): ?>
                    <tr>
                        <td><?= e($m['code']) ?></td>
                        <td><?= e($m['engine_type']) ?></td>
                        <td><?= $m['engine_volume_cm3'] === null ? '—' : (int) $m['engine_volume_cm3'] ?></td>
                        <td><?= (int) $m['power_hp'] ?></td>
                        <td><?= e($m['transmission']) ?></td>
                        <td><?= e($m['drive_type']) ?></td>
                        <td><?= (int) $m['year_start'] ?> — <?= $m['year_end'] === null ? 'н.в.' : (int) $m['year_end'] ?></td>
                        <td><?php status_badge((string) $m['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
layout_footer();