<?php
namespace App\Support;

use App\Exceptions\UserError;
use RuntimeException;
use ZipArchive;

// ============================================================================
// Xlsx: penulis & pembaca .xlsx minimal (ZipArchive + XML), tanpa library luar.
// Penulis: banyak sheet, sel teks/angka, header tebal, lebar kolom.
// Pembaca: sheet pertama, shared strings & inline strings; CSV sebagai cadangan.
// ============================================================================

class Xlsx
{
    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, array<int, array<int, string|int|float|null>>> $sheets nama sheet => baris (baris pertama = header)
     * @return string biner xlsx
     */
    public static function build(array $sheets): string
    {
        if (!self::available()) throw new RuntimeException('Ekstensi PHP zip (ZipArchive) belum aktif di server ini.');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $safe = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', (string) $name) ?? 'Sheet', 0, 31) ?: "Sheet$n";
            $ct .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $wb .= '<sheet name="' . self::x($safe) . "\" sheetId=\"$n\" r:id=\"rId$n\"/>";
            $rels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
            $zip->addFromString("xl/worksheets/sheet$n.xml", self::sheet($rows));
        }
        $rels .= "<Relationship Id=\"rId" . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private static function sheet(array $rows): string
    {
        $widths = [];
        foreach ($rows as $r) {
            foreach (array_values($r) as $i => $v) {
                $widths[$i] = max($widths[$i] ?? 8, min(60, mb_strlen((string) $v) + 2));
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($widths) {
            $xml .= '<cols>';
            foreach ($widths as $i => $w) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . "\" width=\"$w\" customWidth=\"1\"/>";
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach (array_values($rows) as $ri => $r) {
            $xml .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = self::colName($ci) . ($ri + 1);
                $style = $ri === 0 ? ' s="1"' : '';
                if ($v === null || $v === '') {
                    if ($ri === 0) $xml .= "<c r=\"$ref\"$style/>";
                } elseif ((is_int($v) || is_float($v)) && $ri > 0) {
                    $xml .= "<c r=\"$ref\"$style><v>" . $v . '</v></c>';
                } else {
                    $xml .= "<c r=\"$ref\"$style t=\"inlineStr\"><is><t xml:space=\"preserve\">" . self::x((string) $v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    /** Response unduhan .xlsx. */
    public static function download(string $filename, array $sheets): \Symfony\Component\HttpFoundation\Response
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'data.xlsx';

        return response(self::build($sheets), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Baris pertama = header (huruf kecil); kembalikan [header, baris...]. */
    public static function readWithHeader(string $path, string $name): array
    {
        $rows = self::readFile($path, $name);
        $hdr = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($rows) ?? []);

        return [$hdr, $rows];
    }

    public static function col(array $hdr, array $names): ?int
    {
        foreach ($names as $n) {
            $i = array_search($n, $hdr, true);
            if ($i !== false) {
                return $i;
            }
        }

        return null;
    }

    // ---- Pembaca -----------------------------------------------------------------------
    /** @return array<int, array<int, string>> baris (semua sel string) */
    public static function readFile(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') return self::readCsv($path);
        if ($ext !== 'xlsx') throw new UserError('Format berkas harus .xlsx atau .csv.');
        return self::readXlsx($path)[0] ?? [];
    }

    /** Semua sheet berkas: daftar baris per sheet (CSV = satu sheet). @return array<int, array<int, array<int, string>>> */
    public static function readAllSheets(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') return [self::readCsv($path)];
        if ($ext !== 'xlsx') throw new UserError('Format berkas harus .xlsx atau .csv.');
        return self::readXlsx($path, true);
    }

    private static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $first = strtok($raw, "\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null]) continue;
            $rows[] = array_map(fn($v) => trim((string) $v), $r);
        }
        fclose($fh);
        return $rows;
    }

    /** @return array<int, array<int, array<int, string>>> daftar sheet (hanya sheet pertama bila !$all) */
    private static function readXlsx(string $path, bool $all = false): array
    {
        if (!self::available()) throw new UserError('Ekstensi zip tidak aktif di server; unggah dalam format .csv.');
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new UserError('Berkas .xlsx tidak dapat dibuka (rusak atau bukan xlsx).');
        try {
            $shared = [];
            if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $x = @simplexml_load_string($ss);
                if ($x) {
                    foreach ($x->si as $si) {
                        $t = '';
                        if (isset($si->t)) $t = (string) $si->t;
                        else foreach ($si->r as $run) $t .= (string) $run->t;
                        $shared[] = $t;
                    }
                }
            }
            // Cari sheet lewat workbook.xml + rels
            $targets = [];
            $wb = $zip->getFromName('xl/workbook.xml');
            $rl = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($wb !== false && $rl !== false) {
                $w = @simplexml_load_string($wb);
                $r = @simplexml_load_string($rl);
                if ($w && $r && isset($w->sheets->sheet[0])) {
                    $rels = [];
                    foreach ($r->Relationship as $rel) {
                        $t = (string) $rel['Target'];
                        $rels[(string) $rel['Id']] = ltrim(str_starts_with($t, '/') ? $t : 'xl/' . $t, '/');
                    }
                    foreach ($w->sheets->sheet as $sh) {
                        $rid = (string) $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                        if (isset($rels[$rid])) $targets[] = $rels[$rid];
                    }
                }
            }
            if (!$targets) $targets = ['xl/worksheets/sheet1.xml'];
            if (!$all) $targets = [$targets[0]];
            $out = [];
            foreach ($targets as $target) {
                $sx = $zip->getFromName($target);
                if ($sx === false) { if ($all) continue; throw new UserError('Sheet pertama tidak ditemukan pada berkas.'); }
                $sheet = @simplexml_load_string($sx);
                if (!$sheet) { if ($all) continue; throw new UserError('Isi sheet tidak dapat dibaca.'); }
                $rows = [];
                foreach ($sheet->sheetData->row ?? [] as $row) {
                    $cells = [];
                    foreach ($row->c as $c) {
                        preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                        $idx = 0;
                        foreach (str_split($m[1] ?? 'A') as $ch) $idx = $idx * 26 + (ord($ch) - 64);
                        $idx--;
                        $type = (string) $c['t'];
                        if ($type === 's') $val = $shared[(int) $c->v] ?? '';
                        elseif ($type === 'inlineStr') $val = isset($c->is->t) ? (string) $c->is->t : implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($c->is->r ?? [], false)));
                        else $val = (string) $c->v;
                        $cells[$idx] = trim($val);
                    }
                    if ($cells) {
                        $max = max(array_keys($cells));
                        $line = [];
                        for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
                        $rows[] = $line;
                    }
                }
                $out[] = $rows;
            }
            return $out;
        } finally {
            $zip->close();
        }
    }
}
