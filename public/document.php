<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/documents.php');
}
csrf_check();

$type = post_str('type');
if (!in_array($type, ['car_card', 'model_info', 'manufacturer_report'], true)) {
    http_response_code(400);
    exit('Неизвестный тип документа.');
}
$modelId = (int) post_str('model_id');
if ($type !== 'manufacturer_report' && $modelId <= 0) {
    flash_add('error', 'Выберите модель автомобиля.');
    redirect('/documents.php');
}

try {
    $payload = document_payload($type, $modelId > 0 ? $modelId : null);
} catch (RuntimeException $e) {
    flash_add('error', $e->getMessage());
    redirect('/documents.php');
}
$pdf = build_pdf($payload['lines']);
log_action('export', $type === 'manufacturer_report' ? 'manufacturers' : 'car_models',
    $type === 'manufacturer_report' ? null : $modelId, null, ['document' => $type, 'format' => 'pdf']);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $payload['filename'] . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $pdf;
exit;