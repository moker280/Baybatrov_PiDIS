<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();
$models = entity_options('car_models');
$templates = db()->query('SELECT code, title FROM doc_templates ORDER BY id')->fetchAll();

layout_header('Документы');
?>
<h1>Документы</h1>
<p class="muted">Печатные формы заполняются данными из базы по шаблонам без ручного дублирования.
    Шаблоны редактирует администратор в админ-области.</p>
<?php if ($templates === []): ?>
    <div class="card"><p class="empty">Шаблоны не найдены. Импортируйте sql/schema_and_data.sql.</p></div>
<?php endif; ?>
<div class="grid-3">
    <?php foreach ($templates as $t): ?>
        <div class="card">
            <h2><?= e($t['title']) ?></h2>
            <form method="post" action="/document.php">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= e($t['code']) ?>">
                <?php if ($t['code'] !== 'manufacturer_report'): ?>
                    <div class="form-row">
                        <label for="model_<?= e($t['code']) ?>">Модель автомобиля</label>
                        <select id="model_<?= e($t['code']) ?>" name="model_id" required>
                            <option value="">— выберите модель —</option>
                            <?php foreach ($models as $id => $label): ?>
                                <option value="<?= (int) $id ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <p class="muted">Сводный отчёт по всем производителям: количество моделей, выпуск, средняя мощность.</p>
                <?php endif; ?>
                <div class="form-actions">
                    <button class="btn" type="submit">Сформировать PDF</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php
layout_footer();