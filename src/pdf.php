<?php

declare(strict_types=1);

function pdf_is_absolute_path(string $p): bool
{
    return $p !== '' && ($p[0] === '/' || $p[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $p) === 1);
}

function pdf_font_bytes(): string
{
    $configured = (string) cfg('pdf.font', '');
    $candidates = [];
    if ($configured !== '') {
        $candidates[] = pdf_is_absolute_path($configured) ? $configured : APP_ROOT . '/' . $configured;
    }
    foreach ((array) cfg('pdf.fonts', []) as $path) {
        $candidates[] = pdf_is_absolute_path((string) $path) ? $path : APP_ROOT . '/' . $path;
    }
    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
            $bytes = file_get_contents($path);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }
    }
    throw new RuntimeException('В папке fonts/ нет TTF-шрифта с кириллицей. Положите fonts/DejaVuSans.ttf (см. fonts/README.md).');
}

final class Pdf
{
    private Ttf $font;
    private string $fontBytes;
    private string $fontName;
    private array $pages = [];
    private int $pageIndex = -1;
    private array $usedGids = [];
    private array $gidToUnicode = [];

    public function __construct()
    {
        $this->fontBytes = pdf_font_bytes();
        $this->font = new Ttf($this->fontBytes);
        $this->fontName = preg_replace('/[^A-Za-z0-9-]/', '', 'EmbeddedSans') ?: 'EmbeddedSans';
        $this->addPage();
    }

    public function font(): Ttf
    {
        return $this->font;
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->pageIndex++;
    }

    private function encode(string $text): string
    {
        $hex = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $gid = $this->font->gid($cp);
            $this->usedGids[$gid] = true;
            if (!isset($this->gidToUnicode[$gid]) && $cp <= 0xFFFF) {
                $this->gidToUnicode[$gid] = $cp;
            }
            $hex .= sprintf('%04X', $gid);
        }
        return $hex;
    }

    public function text(float $x, float $y, int $size, string $text, bool $bold = false): void
    {
        $hex = $this->encode($text);
        $ops = 'BT';
        if ($bold) {
            $ops .= ' 2 Tr ' . sprintf('%.2F', $size * 0.02) . ' w 0 G';
        } else {
            $ops .= ' 0 Tr';
        }
        $ops .= ' 0 g';
        $ops .= sprintf(' /F1 %d Tf %.2F %.2F Td', $size, $x, $y);
        $ops .= ' <' . $hex . '> Tj ET';
        $this->pages[$this->pageIndex][] = $ops;
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->pages[$this->pageIndex][] = sprintf('0.6 w 0.55 G %.2F %.2F m %.2F %.2F l S 0 G', $x1, $y1, $x2, $y2);
    }

    private function widthArray(): string
    {
        $gids = array_keys($this->usedGids);
        sort($gids);
        $parts = [];
        $i = 0;
        $n = count($gids);
        while ($i < $n) {
            $start = $gids[$i];
            $widths = [$this->font->toPdf($this->font->advance($start))];
            $j = $i + 1;
            while ($j < $n && $gids[$j] === $gids[$j - 1] + 1) {
                $widths[] = $this->font->toPdf($this->font->advance($gids[$j]));
                $j++;
            }
            $parts[] = $start . ' [' . implode(' ', $widths) . ']';
            $i = $j;
        }
        return '[' . implode(' ', $parts) . ']';
    }

    private function toUnicode(): string
    {
        $pairs = [];
        foreach ($this->gidToUnicode as $gid => $cp) {
            $pairs[] = sprintf('<%04X> <%04X>', $gid, $cp);
        }
        $blocks = '';
        foreach (array_chunk($pairs, 100) as $chunk) {
            $blocks .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        return "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $blocks
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    public function output(): string
    {
        $raw = $this->fontBytes;
        $compressed = function_exists('gzcompress') ? gzcompress($raw, 6) : false;
        $fontStream = $compressed !== false ? $compressed : $raw;
        $fontFilter = $compressed !== false ? ' /Filter /FlateDecode' : '';
        $tu = $this->toUnicode();
        $f = $this->font;
        $bbox = '[' . $f->toPdf($f->bbox[0]) . ' ' . $f->toPdf($f->bbox[1]) . ' ' . $f->toPdf($f->bbox[2]) . ' ' . $f->toPdf($f->bbox[3]) . ']';
        $flags = $f->italicAngle !== 0 ? 68 : 4;
        $n = count($this->pages);

        $kids = implode(' ', array_map(static fn (int $i): string => (10 + 2 * $i) . ' 0 R', range(0, $n - 1)));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            "<< /Type /Pages /Kids [{$kids}] /Count {$n} >>",
            "<< /Type /Font /Subtype /Type0 /BaseFont /{$this->fontName} /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 8 0 R >>",
            "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$this->fontName} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /CIDToGIDMap /Identity /W " . $this->widthArray() . ' >>',
            "<< /Type /FontDescriptor /FontName /{$this->fontName} /Flags {$flags} /FontBBox {$bbox} /ItalicAngle {$f->italicAngle} /Ascent " . $f->toPdf($f->ascent) . ' /Descent ' . $f->toPdf($f->descent) . ' /CapHeight ' . $f->toPdf($f->capHeight) . ' /StemV 80 /FontFile2 6 0 R >>',
            '<< /Length ' . strlen($fontStream) . ' /Length1 ' . strlen($raw) . $fontFilter . " >>\nstream\n" . $fontStream . "\nendstream",
            '<< /Length 0 >> stream endstream',
            '<< /Length ' . strlen($tu) . " >>\nstream\n" . $tu . "\nendstream",
            '<< /Length 0 >> stream endstream',
        ];
        foreach ($this->pages as $i => $ops) {
            $stream = implode("\n", $ops);
            $contentNum = 11 + 2 * $i;
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> '
                . "/Contents {$contentNum} 0 R >>";
            $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $out = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $out;
    }
}

function pdf_wrap_line(string $line, Ttf $font, float $maxWidth, int $size): array
{
    if ($line === '') {
        return [''];
    }
    $spaceW = $font->stringWidth(' ', $size) ?: $size * 0.3;
    $result = [];
    $current = '';
    $currentW = 0.0;
    foreach (explode(' ', $line) as $word) {
        $wordW = $font->stringWidth($word, $size);
        if ($current !== '' && $currentW + $spaceW + $wordW > $maxWidth) {
            $result[] = $current;
            $current = '';
            $currentW = 0.0;
        }
        if ($wordW > $maxWidth) {
            if ($current !== '') {
                $result[] = $current;
                $current = '';
                $currentW = 0.0;
            }
            $piece = '';
            $pieceW = 0.0;
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $cw = $font->stringWidth($ch, $size);
                if ($piece !== '' && $pieceW + $cw > $maxWidth) {
                    $result[] = $piece;
                    $piece = '';
                    $pieceW = 0.0;
                }
                $piece .= $ch;
                $pieceW += $cw;
            }
            $current = $piece;
            $currentW = $pieceW;
            continue;
        }
        $current = $current === '' ? $word : $current . ' ' . $word;
        $currentW = $current === $word ? $wordW : $currentW + $spaceW + $wordW;
    }
    $result[] = $current;
    return $result;
}

function build_pdf(array $lines): string
{
    $pdf = new Pdf();
    $font = $pdf->font();
    $maxWidth = 511.0;
    $y = 806.0;
    $page = 1;
    $first = true;
    foreach ($lines as $line) {
        $size = $first ? 14 : 11;
        foreach (pdf_wrap_line((string) $line, $font, $maxWidth, $size) as $i => $chunk) {
            if ($y < 56) {
                $pdf->text(42, 30, 9, 'АИС «СРА» — страница ' . $page);
                $pdf->addPage();
                $page++;
                $y = 806.0;
            }
            $pdf->text(42, $y, $size, $chunk, $first && $i === 0);
            if ($first && $i === 0) {
                $pdf->line(42, $y - 8, 553, $y - 8);
                $y -= 8;
            }
            $y -= 18;
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