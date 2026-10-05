<?php

/* ==========================================================
   UTILIDADES
   ========================================================== */

function terminar(string $mensaje): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=UTF-8');   // evita el "vÃ¡lidos"
    exit($mensaje);
}

function celdaTexto($v): string
{
    if (is_array($v)) {
        return implode(', ', array_map('celdaTexto', $v));
    }
    return trim((string)$v);
}

function colLetra(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function xmlTexto(string $s): string
{
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}


/* ==========================================================
   LEER LOS DATOS (acepta varias formas de envío)
   ========================================================== */

function normalizarDatos(array $d): ?array
{
    // Por si llega envuelto: {"datos": {"columnas": ..., "datos": ...}}
    if (isset($d['datos']['columnas']) && is_array($d['datos'])) {
        $d = $d['datos'];
    }

    $columnas = array_values(array_map('celdaTexto', (array)($d['columnas'] ?? [])));
    $filasIn  = is_array($d['datos'] ?? null) ? $d['datos'] : [];

    if (!$columnas && !$filasIn) {
        return null;
    }
    if (!$columnas) {
        $primera = reset($filasIn);
        $n = is_array($primera) ? count($primera) : 1;
        $columnas = array_map(fn($i) => 'Columna ' . ($i + 1), range(0, max($n, 1) - 1));
    }

    $n = count($columnas);
    $filas = [];
    foreach ($filasIn as $fila) {
        if (!is_array($fila)) {
            $fila = [$fila];
        }
        $esAsociativa = $fila && array_keys($fila) !== range(0, count($fila) - 1);
        if ($esAsociativa) {
            $valores = [];
            foreach ($columnas as $i => $nombre) {
                $valores[] = array_key_exists($nombre, $fila)
                    ? celdaTexto($fila[$nombre])
                    : celdaTexto(array_values($fila)[$i] ?? '');
            }
        } else {
            $valores = array_map('celdaTexto', array_values($fila));
        }
        $filas[] = array_slice(array_pad($valores, $n, ''), 0, $n);   // sin claves: siempre lista
    }

    if (!$filas) {
        $filas = [array_pad(['Sin datos'], $n, '')];
    }

    return [
        'titulo' => celdaTexto($d['titulo'] ?? '') ?: 'Archivo Excel',
        'columnas' => $columnas,
        'filas' => $filas,
    ];
}

function leerDatos(string &$rawDebug): ?array
{
    $candidatos = [];

    if (isset($_POST['datos'])) {
        if (is_array($_POST['datos'])) {
            $r = normalizarDatos($_POST['datos']);
            if ($r) {
                return $r;
            }
        } else {
            $candidatos[] = (string)$_POST['datos'];
        }
    }

    $raw = file_get_contents('php://input');
    $rawDebug = (string)$raw;
    if ($raw !== false && $raw !== '') {
        $candidatos[] = $raw;
        parse_str($raw, $p);
        if (isset($p['datos']) && is_string($p['datos'])) {
            $candidatos[] = $p['datos'];
        }
    }

    foreach ($candidatos as $c) {
        $c = trim(preg_replace('/^\xEF\xBB\xBF/', '', $c));
        $variantes = [
            $c,
            stripslashes($c),
            html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            urldecode($c),
        ];
        foreach ($variantes as $v) {
            $d = json_decode($v, true);
            if (is_array($d)) {
                $r = normalizarDatos($d);
                if ($r) {
                    return $r;
                }
            }
        }
    }
    return null;
}


/* ==========================================================
   CREAR XLSX REAL (sin librerías, solo ZipArchive)
   Estilos: 0 = normal, 1 = encabezado (con borde), 2 = datos (con borde)
   ========================================================== */

function crearXlsx(array $x): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $columnas = $x['columnas'];
    $filas = $x['filas'];
    $nCols = count($columnas);

    // Anchos de columna
    $anchos = [];
    foreach ($columnas as $i => $c) {
        $max = mb_strlen($c);
        foreach ($filas as $f) {
            $max = max($max, mb_strlen($f[$i] ?? ''));
        }
        $anchos[$i] = min(60, max(12, $max + 2));
    }

    $cols = '';
    foreach ($anchos as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    // Fila de encabezados (estilo 1)
    $sheetRows = '<row r="1">';
    foreach ($columnas as $i => $c) {
        $sheetRows .= '<c r="' . colLetra($i) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">'
                    . xmlTexto($c) . '</t></is></c>';
    }
    $sheetRows .= '</row>';

    // Filas de datos (estilo 2: con borde)
    foreach ($filas as $r => $fila) {
        $num = $r + 2;
        $sheetRows .= '<row r="' . $num . '">';
        for ($i = 0; $i < $nCols; $i++) {
            $ref = colLetra($i) . $num;
            $v = (string)($fila[$i] ?? '');
            if (preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,10})?$/', $v)) {
                $sheetRows .= '<c r="' . $ref . '" s="2"><v>' . $v . '</v></c>';
            } else {
                $sheetRows .= '<c r="' . $ref . '" s="2" t="inlineStr"><is><t xml:space="preserve">'
                            . xmlTexto($v) . '</t></is></c>';
            }
        }
        $sheetRows .= '</row>';
    }

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $xmlHead = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $nombreHoja = preg_replace('/[\[\]:*?\/\\\\]/u', '', $x['titulo']);
    $nombreHoja = mb_substr(trim($nombreHoja), 0, 31) ?: 'Datos';

    $lado = '<color rgb="FF999999"/>';
    $borde = '<border>'
           . '<left style="thin">' . $lado . '</left>'
           . '<right style="thin">' . $lado . '</right>'
           . '<top style="thin">' . $lado . '</top>'
           . '<bottom style="thin">' . $lado . '</bottom>'
           . '<diagonal/></border>';

    $archivos = [
        '[Content_Types].xml' => $xmlHead
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' => $xmlHead
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' => $xmlHead
            . '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets>'
            . '<sheet name="' . xmlTexto($nombreHoja) . '" sheetId="1" r:id="rId1"/>'
            . '</sheets></workbook>',

        'xl/_rels/workbook.xml.rels' => $xmlHead
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/>'
            . '</Relationships>',

        'xl/styles.xml' => $xmlHead
            . '<styleSheet xmlns="' . $ns . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F2A44"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . $borde
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs></styleSheet>',

        'xl/worksheets/sheet1.xml' => $xmlHead
            . '<worksheet xmlns="' . $ns . '">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '</worksheet>',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    foreach ($archivos as $nombre => $contenido) {
        $zip->addFromString($nombre, $contenido);
    }
    $zip->close();

    return $tmp;
}


/* ==========================================================
   FLUJO PRINCIPAL
   ========================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    terminar('Solicitud no válida.');
}

$rawDebug = '';
$x = leerDatos($rawDebug);

if (!$x) {
    // Guardamos lo que llegó para poder ver qué está enviando la página
    file_put_contents(
        __DIR__ . '/debug_excel_post.txt',
        "POST:\n" . print_r($_POST, true) . "\nRAW:\n" . $rawDebug . "\n"
    );
    terminar('No se recibieron datos válidos. Revisa el archivo debug_excel_post.txt junto a este script.');
}

// Nombre de archivo seguro
$nombre = strtr($x['titulo'], [
    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
    'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
]);
$nombre = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $nombre), '_');
if ($nombre === '') {
    $nombre = 'Alex_Excel';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$tmp = crearXlsx($x);

if ($tmp !== null && is_file($tmp)) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: max-age=0');
    readfile($tmp);
    unlink($tmp);
    exit;
}

/* ---------- Plan B: HTML compatible con Excel (si falta la extensión zip) ---------- */

$html = "\xEF\xBB\xBF<html><head><meta charset=\"UTF-8\"><style>"
      . 'table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px}'
      . 'th{background:#1F2A44;color:#fff;font-weight:bold}</style></head><body><table><tr>';
foreach ($x['columnas'] as $c) {
    $html .= '<th>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</th>';
}
$html .= '</tr>';
foreach ($x['filas'] as $fila) {
    $html .= '<tr>';
    foreach ($fila as $v) {
        $html .= '<td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td>';
    }
    $html .= '</tr>';
}
$html .= '</table></body></html>';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre . '.xls"');
header('Cache-Control: max-age=0');
echo $html;
exit;