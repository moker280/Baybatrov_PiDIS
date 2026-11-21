<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();

$stats = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM manufacturers) AS manufacturers,
        (SELECT COUNT(*) FROM car_models) AS models,
        (SELECT COUNT(*) FROM modifications) AS modifications,
        (SELECT COUNT(*) FROM car_models WHERE status = \'in_production\') AS in_production'
)->fetch();

$filters = [
    'q' => get_str('q'),
    'manufacturer_id' => (int) get_str('manufacturer_id'),
    'body_type' => get_str('body_type'),
    'status' => get_str('status'),
];
$searched = $filters['q'] !== '' || $filters['manufacturer_id'] > 0 || $filters['body_type'] !== '' || $filters['status'] !== '';
$results = [];
if ($searched) {
    $results = call_procedure('CALL sp_search_cars(?, ?, ?, ?)', [
        $filters['q'],
        $filters['manufacturer_id'],
        $filters['body_type'],
        $filters['status'],
    ]);
}
$manufacturers = entity_options('manufacturers');
$bodyOptions = entity_schema('car_models')['fields']['body_type']['options'];

layout_header('Подборка автомобилей');
?>
<h1>Справочник российских автомобилей</h1>
<div class="stats">
    <div class="stat"><span class="stat-value"><?= (int) $stats['manufacturers'] ?></span><span class="stat-label">Производителей</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['models'] ?></span><span class="stat-label">Моделей</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['modifications'] ?></span><span class="stat-label">Модификаций</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $stats['in_production'] ?></span><span class="stat-label">В производстве</span></div>
</div>
<div class="card">
    <h2>Подборка автомобилей по фильтрам</h2>
    <form method="get" action="/index.php" class="filters">
        <div class="form-row">
            <label for="q">Поиск (производитель, модель, код модификации)</label>
            <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" maxlength="100">
        </div>
        <div class="form-row">
            <label for="manufacturer_id">Производитель</label>
            <select id="manufacturer_id" name="manufacturer_id">
                <option value="0">Все</option>
                <?php foreach ($manufacturers as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= $filters['manufacturer_id'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="body_type">Кузов</label>
            <select id="body_type" name="body_type">
                <option value="">Все</option>
                <?php foreach ($bodyOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['body_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="status">Статус</label>
            <select id="status" name="status">
                <option value="">Все</option>
                <option value="in_production"<?= $filters['status'] === 'in_production' ? ' selected' : '' ?>>Производится</option>
                <option value="discontinued"<?= $filters['status'] === 'discontinued' ? ' selected' : '' ?>>Снят с производства</option>
            </select>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Подобрать</button>
            <a class="btn-link" href="/index.php">Сбросить</a>
        </div>
    </form>
</div>
<?php if ($searched): ?>
    <div class="card">
        <h2>Результаты (<?= count($results) ?>)</h2>
        <?php if ($results === []): ?>
            <p class="empty">По заданным условиям автомобили не найдены. Измените фильтры.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="list">
                    <thead>
                    <tr><th>Производитель</th><th>Модель</th><th>Кузов</th><th>Годы выпуска</th><th>Статус</th><th>Модификаций</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= e($row['manufacturer_name']) ?></td>
                            <td><?= e($row['model_name']) ?></td>
                            <td><?= e($row['body_type']) ?></td>
                            <td><?= e($row['production_years']) ?></td>
                            <td><?php status_badge((string) $row['status']); ?></td>
                            <td><?= (int) $row['modifications_count'] ?></td>
                            <td><a class="btn btn-small" href="/model_card.php?id=<?= (int) $row['id'] ?>">Карточка</a></td>
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