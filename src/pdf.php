<?php

declare(strict_types=1);

final class Pdf
{
    private array $pages = [];
    private int $pageIndex = -1;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->pageIndex++;
    }

    public function text(float $x, float $y, int $size, string $text, bool $bold = false): void
    {
        $cp = mb_convert_encoding($text, 'CP1251', 'UTF-8');
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cp);
        $this->pages[$this->pageIndex][] = sprintf(
            'BT %s %d Tf %.2F %.2F Td (%s) Tj ET',
            $bold ? '/F2' : '/F1',
            $size,
            $x,
            $y,
            $escaped
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->pages[$this->pageIndex][] = sprintf('0.6 w 0.55 G %.2F %.2F m %.2F %.2F l S 0 G', $x1, $y1, $x2, $y2);
    }

    public function output(): string
    {
        $n = count($this->pages);
        $kids = implode(' ', array_map(static fn (int $i): string => (6 + 2 * $i) . ' 0 R', range(0, $n - 1)));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            "<< /Type /Pages /Kids [{$kids}] /Count {$n} >>",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 5 0 R >>',
            '<< /Type /Encoding /Differences ' . pdf_font_differences() . ' >>',
        ];
        foreach ($this->pages as $i => $ops) {
            $stream = implode("\n", $ops);
            $contentNum = 7 + 2 * $i;
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> '
                . "/Contents {$contentNum} 0 R >>";
            $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n" . "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        $out .= 'trailer' . "\n" . '<< /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n"
            . "startxref\n" . $xref . "\n%%EOF";
        return $out;
    }
}

function pdf_font_differences(): string
{
    // Коды CP1251 -> имена глифов Adobe Glyph List. Стандартные шрифты PDF не содержат кириллицу,
    // но просмотрщики (Adobe, Chrome/PDFium, Edge) сопоставляют afii-имена с Unicode и подставляют шрифт.
    $map = [
        132 => 'quotedblbase', 133 => 'ellipsis', 147 => 'quotedblleft', 148 => 'quotedblright',
        149 => 'bullet', 150 => 'endash', 151 => 'emdash', 168 => 'afii10023', 171 => 'guillemotleft',
        184 => 'afii10071', 185 => 'afii61352', 187 => 'guillemotright',
    ];
    for ($i = 0; $i < 6; $i++) {
        $map[192 + $i] = 'afii' . (10017 + $i); // А..Е
        $map[224 + $i] = 'afii' . (10065 + $i); // а..е
    }
    for ($i = 0; $i < 26; $i++) {
        $map[198 + $i] = 'afii' . (10024 + $i); // Ж..Я (Ё в таблице afii идёт отдельно)
        $map[230 + $i] = 'afii' . (10072 + $i); // ж..я
    }
    ksort($map);
    $parts = [];
    foreach ($map as $code => $name) {
        $parts[] = $code . ' /' . $name;
    }
    return '[' . implode(' ', $parts) . ']';
}

function pdf_wrap_line(string $line, int $size): array
{
    $maxChars = max(20, (int) floor(511 / ($size * 0.52)));
    if ($line === '') {
        return [''];
    }
    $result = [];
    $current = '';
    foreach (explode(' ', $line) as $word) {
        while (mb_strlen($word) > $maxChars) {
            if ($current !== '') {
                $result[] = $current;
                $current = '';
            }
            $result[] = mb_substr($word, 0, $maxChars);
            $word = mb_substr($word, $maxChars);
        }
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (mb_strlen($candidate) > $maxChars) {
            $result[] = $current;
            $current = $word;
        } else {
            $current = $candidate;
        }
    }
    $result[] = $current;
    return $result;
}

function build_pdf(array $lines): string
{
    $pdf = new Pdf();
    $y = 800.0;
    $page = 1;
    $first = true;
    foreach ($lines as $line) {
        $size = $first ? 14 : 11;
        foreach (pdf_wrap_line((string) $line, $size) as $i => $chunk) {
            if ($y < 60) {
                $pdf->text(42, 30, 9, 'АИС «СРА» — страница ' . $page);
                $pdf->addPage();
                $page++;
                $y = 800.0;
            }
            $pdf->text(42, $y, $size, $chunk, $first && $i === 0);
            if ($first && $i === 0) {
                $pdf->line(42, $y - 6, 553, $y - 6);
                $y -= 10;
            }
            $y -= 16;
        }
        $first = false;
    }
    $pdf->text(42, 30, 9, 'АИС «СРА» — страница ' . $page);
    return $pdf->output();
}

function replace_placeholders(string $line, array $vars): string
{
    foreach ($vars as $key => $value) {
        $line = str_replace('{{' . $key . '}}', (string) ($value ?? '—'), $line);
    }
    return $line;
}

function render_template(string $body, array $scalars, array $rows): array
{
    $lines = [];
    $rowTemplate = [];
    $inRows = false;
    foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
        if (trim($line) === '{{ROWS}}') {
            $inRows = true;
            continue;
        }
        if (trim($line) === '{{ENDROWS}}') {
            $inRows = false;
            foreach ($rows as $row) {
                foreach ($rowTemplate as $tpl) {
                    $lines[] = replace_placeholders($tpl, $row);
                }
            }
            $rowTemplate = [];
            continue;
        }
        if ($inRows) {
            $rowTemplate[] = $line;
            continue;
        }
        $lines[] = replace_placeholders($line, $scalars);
    }
    return $lines;
}

function template_defaults(): array
{
    return [
        'car_card' => [
            'title' => 'Карточка автомобиля',
            'body' => <<<'TXT'
КАРТОЧКА АВТОМОБИЛЯ
Производитель: {{manufacturer_name}} ({{country}}, год основания {{founded_year}})
Модель: {{model_name}}
Тип кузова: {{body_type}}
Годы производства: {{production_years}}
Статус производства: {{status_label}}
Число модификаций: {{modifications_count}}

Модификации:
{{ROWS}}
{{code}} | {{engine_type}} | {{engine_volume}} | {{power_hp}} л.с. | {{transmission}} | привод {{drive_type}} | {{years}}
{{ENDROWS}}

Сформировано: {{generated_at}}. АИС «Справочник российских автомобилей»
TXT,
        ],
        'model_info' => [
            'title' => 'Справка о модели',
            'body' => <<<'TXT'
СПРАВКА О МОДЕЛИ
Модель: {{model_name}}
Производитель: {{manufacturer_name}} ({{country}})
Кузов: {{body_type}}. Выпуск: {{production_years}}
Статус производства: {{status_label}}
Модификаций в справочнике: {{modifications_count}}
Диапазон мощности: {{power_range}} л.с.

Справка сформирована по данным базы: {{generated_at}}
TXT,
        ],
        'manufacturer_report' => [
            'title' => 'Отчёт по производителям',
            'body' => <<<'TXT'
ОТЧЁТ ПО ПРОИЗВОДИТЕЛЯМ
Дата формирования: {{generated_at}}
Всего производителей: {{total_manufacturers}}

{{ROWS}}
{{name}}: моделей {{models_count}}, в производстве {{in_production_count}}, модификаций {{modifications_count}}, средняя мощность {{avg_power}} л.с.
{{ENDROWS}}

АИС «Справочник российских автомобилей»
TXT,
        ],
    ];
}

function document_payload(string $type, ?int $modelId): array
{
    $stmt = db()->prepare('SELECT * FROM doc_templates WHERE code = ?');
    $stmt->execute([$type]);
    $template = $stmt->fetch();
    if (!$template) {
        throw new RuntimeException('Шаблон документа не найден в базе данных.');
    }
    $scalars = ['generated_at' => date('d.m.Y H:i')];
    $rows = [];
    if ($type === 'car_card' || $type === 'model_info') {
        $stmt = db()->prepare(
            'SELECT c.*, mf.name AS manufacturer_name, mf.country, mf.founded_year,
                    fn_model_production_years(c.id) AS production_years
             FROM car_models c JOIN manufacturers mf ON mf.id = c.manufacturer_id WHERE c.id = ?'
        );
        $stmt->execute([(int) $modelId]);
        $model = $stmt->fetch();
        if (!$model) {
            throw new RuntimeException('Модель не найдена.');
        }
        $stmt = db()->prepare('SELECT * FROM modifications WHERE car_model_id = ? ORDER BY code');
        $stmt->execute([(int) $modelId]);
        $mods = $stmt->fetchAll();
        $powers = array_map(static fn (array $m): int => (int) $m['power_hp'], $mods);
        $scalars += [
            'manufacturer_name' => $model['manufacturer_name'],
            'country' => $model['country'],
            'founded_year' => $model['founded_year'],
            'model_name' => $model['name'],
            'body_type' => $model['body_type'],
            'production_years' => $model['production_years'],
            'status_label' => status_label((string) $model['status']),
            'modifications_count' => count($mods),
            'power_range' => $powers === [] ? '—' : min($powers) . '—' . max($powers),
        ];
        foreach ($mods as $m) {
            $rows[] = [
                'code' => $m['code'],
                'engine_type' => $m['engine_type'],
                'engine_volume' => $m['engine_volume_cm3'] === null ? '—' : $m['engine_volume_cm3'] . ' см³',
                'power_hp' => $m['power_hp'],
                'transmission' => $m['transmission'],
                'drive_type' => $m['drive_type'],
                'years' => $m['year_start'] . ' — ' . ($m['year_end'] ?? 'н.в.'),
            ];
        }
        $filename = $type . '_model_' . (int) $modelId . '.pdf';
    } else {
        $rows = call_procedure('CALL sp_manufacturer_report()');
        foreach ($rows as &$row) {
            $row['avg_power'] = $row['avg_power'] ?? '—';
        }
        unset($row);
        $scalars['total_manufacturers'] = count($rows);
        $filename = 'manufacturer_report_' . date('Ymd_His') . '.pdf';
    }
    return [
        'lines' => render_template((string) $template['body'], $scalars, $rows),
        'filename' => $filename,
    ];
}