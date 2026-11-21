<?php

declare(strict_types=1);

final class Ttf
{
    private string $data;
    private array $tables = [];
    public int $unitsPerEm = 1000;
    public int $numGlyphs = 0;
    public int $ascent = 800;
    public int $descent = -200;
    public int $capHeight = 700;
    public int $italicAngle = 0;
    public array $bbox = [0, 0, 1000, 1000];
    private int $numHMetrics = 0;
    private $cmap = null;
    private array $gidCache = [];
    private array $advCache = [];

    public function __construct(string $bytes)
    {
        if (strncmp($bytes, 'OTTO', 4) === 0) {
            throw new RuntimeException('Шрифт OpenType/CFF не поддерживается. Укажите TrueType (.ttf): DejaVu Sans или Liberation Sans.');
        }
        $this->data = $bytes;
        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $p = 12 + $i * 16;
            $tag = substr($this->data, $p, 4);
            $this->tables[$tag] = ['offset' => $this->u32($p + 8), 'length' => $this->u32($p + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new RuntimeException('Повреждённый шрифт: нет таблицы ' . $required . '.');
            }
        }
        $head = $this->tables['head']['offset'];
        $this->unitsPerEm = $this->u16($head + 18) ?: 1000;
        $this->bbox = [
            $this->s16($head + 36), $this->s16($head + 38),
            $this->s16($head + 40), $this->s16($head + 42),
        ];
        $hhea = $this->tables['hhea']['offset'];
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $this->numHMetrics = $this->u16($hhea + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp']['offset'] + 4);
        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2']['offset'];
            if (($this->u16($os2) ?: 0) >= 2) {
                $this->capHeight = $this->s16($os2 + 88);
            }
        }
        if ($this->capHeight <= 0) {
            $this->capHeight = (int) round($this->ascent * 0.7);
        }
        if (isset($this->tables['post'])) {
            $this->italicAngle = $this->s16($this->tables['post']['offset'] + 4);
        }
        $this->cmap = $this->pickCmap();
    }

    private function u8(int $p): int
    {
        return ord($this->data[$p]);
    }

    private function u16(int $p): int
    {
        return unpack('n', substr($this->data, $p, 2))[1];
    }

    private function u32(int $p): int
    {
        return unpack('N', substr($this->data, $p, 4))[1];
    }

    private function s16(int $p): int
    {
        $v = $this->u16($p);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function s32(int $p): int
    {
        $v = $this->u32($p);
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private function pickCmap(): ?array
    {
        $base = $this->tables['cmap']['offset'];
        $n = $this->u16($base + 2);
        $best = null;
        $bestScore = -1;
        for ($i = 0; $i < $n; $i++) {
            $rec = $base + 4 + $i * 8;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $off = $this->u32($rec + 4);
            $sub = $base + $off;
            $format = $this->u16($sub);
            $unicode = ($platform === 0) || ($platform === 3 && in_array($encoding, [1, 10], true));
            if (!$unicode) {
                continue;
            }
            $score = 0;
            if ($format === 12) {
                $score = 3;
            } elseif ($format === 4) {
                $score = 2;
            } else {
                continue;
            }
            if ($platform === 3 && $encoding === 10) {
                $score++;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['offset' => $sub, 'format' => $format];
            }
        }
        return $best;
    }

    public function gid(int $cp): int
    {
        if (isset($this->gidCache[$cp])) {
            return $this->gidCache[$cp];
        }
        $gid = 0;
        if ($this->cmap !== null) {
            $gid = $this->cmap['format'] === 12
                ? $this->cmap12($this->cmap['offset'], $cp)
                : $this->cmap4($this->cmap['offset'], $cp);
        }
        return $this->gidCache[$cp] = $gid;
    }

    private function cmap4(int $s, int $cp): int
    {
        if ($cp > 0xFFFF) {
            return 0;
        }
        $segX2 = $this->u16($s + 6);
        $seg = intdiv($segX2, 2);
        $endO = $s + 14;
        $startO = $endO + $segX2 + 2;
        $deltaO = $startO + $segX2;
        $rangeO = $deltaO + $segX2;
        for ($i = 0; $i < $seg; $i++) {
            $end = $this->u16($endO + $i * 2);
            if ($cp > $end) {
                continue;
            }
            $start = $this->u16($startO + $i * 2);
            if ($cp < $start) {
                return 0;
            }
            $delta = $this->s16($deltaO + $i * 2);
            $ro = $this->u16($rangeO + $i * 2);
            if ($ro === 0) {
                return ($cp + $delta) & 0xFFFF;
            }
            $addr = $rangeO + $i * 2 + $ro + ($cp - $start) * 2;
            $g = $this->u16($addr);
            return $g === 0 ? 0 : ($g + $delta) & 0xFFFF;
        }
        return 0;
    }

    private function cmap12(int $s, int $cp): int
    {
        $ng = $this->u32($s + 12);
        for ($i = 0; $i < $ng; $i++) {
            $g = $s + 16 + $i * 12;
            $start = $this->u32($g);
            $end = $this->u32($g + 4);
            if ($cp < $start) {
                return 0;
            }
            if ($cp <= $end) {
                return ($this->u32($g + 8) + ($cp - $start)) & 0xFFFF;
            }
        }
        return 0;
    }

    public function advance(int $gid): int
    {
        if (isset($this->advCache[$gid])) {
            return $this->advCache[$gid];
        }
        $hmtx = $this->tables['hmtx']['offset'];
        $adv = $gid < $this->numHMetrics
            ? $this->u16($hmtx + $gid * 4)
            : $this->u16($hmtx + ($this->numHMetrics - 1) * 4);
        return $this->advCache[$gid] = $adv;
    }

    public function toPdf(int $units): int
    {
        return (int) round($units * 1000 / $this->unitsPerEm);
    }

    public function charWidth(int $cp, float $size): float
    {
        return $this->toPdf($this->advance($this->gid($cp))) / 1000 * $size;
    }

    public function stringWidth(string $text, float $size): float
    {
        $w = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $w += $this->charWidth(mb_ord($ch, 'UTF-8'), $size);
        }
        return $w;
    }
}