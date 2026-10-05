<?php

set_time_limit(900);
ini_set('max_execution_time', '900');
ini_set('display_errors', 0);          // un warning no debe dañar el JSON de salida
ini_set('log_errors', 1);
ini_set('zlib.output_compression', '0');
error_reporting(E_ALL);

/* ==========================================================
   CONFIGURACIÓN
   ========================================================== */


const OLLAMA_MODELO = 'llama3.2:3b';


const CLAVE_MANUAL = '';

function cargarClave(): string
{
    if (CLAVE_MANUAL !== '') {
        return trim(CLAVE_MANUAL);
    }
    $archivo = __DIR__ . '/ollama_key.txt';
    if (is_file($archivo)) {
        $k = trim((string)file_get_contents($archivo));
        if ($k !== '') {
            return $k;
        }
    }
    foreach ([getenv('OLLAMA_API_KEY'), $_SERVER['OLLAMA_API_KEY'] ?? '', $_ENV['OLLAMA_API_KEY'] ?? ''] as $k) {
        if (is_string($k) && trim($k) !== '') {
            return trim($k);
        }
    }
    return '';
}

define('OLLAMA_API_KEY', cargarClave());

$INICIO = microtime(true);
$FUENTES = [];   // url => título (se llena durante la investigación)
$ERRORES_BUSQUEDA = [];      // proveedor => motivo del fallo
$PROVEEDORES_CAIDOS = [];    // buscadores que fallaron (no se reintentan)
$PLAN_BUSQUEDA = null;       // consultas es/en calculadas una vez
$SOLICITUD_ACTUAL = '';


/* ==========================================================
   UTILIDADES
   ========================================================== */

function salir(array $datos): void
{
    guardarDebug('tiempo total', round(microtime(true) - ($GLOBALS['INICIO'] ?? microtime(true)), 1) . ' s');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Envía un espacio para que navegador/proxy no cierren la conexión por inactividad.
function latido(): void
{
    echo ' ';
    @flush();
}

function guardarDebug(string $etiqueta, string $contenido): void
{
    file_put_contents(
        __DIR__ . '/debug_ultima_respuesta.json',
        '### ' . $etiqueta . "\n" . $contenido . "\n\n",
        FILE_APPEND
    );
}

function celdaATexto($v): string
{
    if (is_array($v)) {
        return implode(', ', array_map('celdaATexto', $v));
    }
    return trim((string)$v);
}

function contarPalabras(string $txt): int
{
    return count(preg_split('/\s+/u', trim($txt), -1, PREG_SPLIT_NO_EMPTY));
}

function limpiarJson(string $t): string
{
    $t = preg_replace('/```json\s*/i', '', $t);
    return trim(preg_replace('/```\s*/', '', $t));
}

function numeroPalabra(string $s): int
{
    $m = ['un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3,
          'cuatro' => 4, 'cinco' => 5, 'seis' => 6];
    $s = mb_strtolower($s, 'UTF-8');
    return ctype_digit($s) ? (int)$s : ($m[$s] ?? 0);
}


/* ==========================================================
   INTERPRETAR LA SOLICITUD (según lo que escribas)
   ========================================================== */

function analizarSolicitud(string $solicitud): array
{
    $min = mb_strtolower($solicitud, 'UTF-8');

    $esExcel = str_contains($min, 'excel') || str_contains($min, 'xlsx')
            || str_contains($min, 'hoja de cálculo') || str_contains($min, 'hoja de calculo');
    $esPDF = str_contains($min, 'pdf');

    // Dashboard: "dashboard", "tablero", "panel de control", "kpi", "indicadores"
    $esDashboard = (bool)preg_match('/\b(dashboards?|tablero|panel|kpis?|indicadores|m[eé]tricas|estad[ií]sticas|gr[aá]ficos?|gr[aá]ficas?)\b/iu', $min);

    // Hojas: "2 hojas", "dos págs", "una hoja completa"
    $hojas = 0;
    if (preg_match('/\b(\d+|una|un|dos|tres|cuatro|cinco)\s+(hojas?|p[aá]ginas?|p[aá]gs?)\b/iu', $min, $m)) {
        $hojas = numeroPalabra($m[1]);
    } elseif (preg_match('/\b(hoja|p[aá]gina)\s+completa/iu', $min)) {
        $hojas = 1;
    }

    // Tablas: "3 tablas", "tablas" (plural = 3), "una tabla", "lista/listado" = 1
    $nTablas = 0;
    if (preg_match('/\b(\d+|una|un|dos|tres|cuatro|cinco|seis)\s+tablas?\b/iu', $min, $m)) {
        $nTablas = max(1, numeroPalabra($m[1]));
    } elseif (preg_match('/\btablas\b/iu', $min)) {
        $nTablas = 3;
    } elseif (preg_match('/\b(tabla|lista|listado|ranking|top)\b/iu', $min)) {
        $nTablas = 1;
    }

    // Palabras que activan la profundidad y la investigación
    $profundo  = (bool)preg_match('/(profund|a fondo|detallad|exhaustiv|completo|extens)/iu', $min);
    $investigar = (bool)preg_match(
        '/(investig|busca|b[uú]squeda|a fondo|actualiz|actuales|[uú]ltimos|recientes|en internet|en la web|fuentes)/iu',
        $min
    );

    // Carta o mensaje personal
    $esCarta = (bool)preg_match(
        '/\b(carta|mensaje|dedicatoria|felicitaci[oó]n|reclamo|despedida|declaraci[oó]n)\b'
        . '|\b(dile|d[ií]selo|diciendole|dici[eé]ndole|escr[ií]bele)\b/iu',
        $min
    );

    if ($nTablas > 0 && $profundo) {
        $nTablas = max($nTablas, 3);
    }

    // Años mencionados (2025 en adelante el modelo local no los conoce: requiere búsqueda web)
    preg_match_all('/\b(20\d{2})\b/', $min, $mAnios);
    $anios = array_values(array_unique(array_map('intval', $mAnios[1] ?? [])));
    sort($anios);
    $anio = $anios ? max($anios) : 0;
    if ($anio >= 2025) {
        $investigar = true;
    }

    // "Genera una tabla en pdf": solo la tabla, sin texto de relleno
    $soloTabla = $nTablas > 0 && $hojas === 0 && !$profundo;

    $objetivo = $hojas > 0 ? $hojas * 450 : ($profundo ? 700 : ($nTablas > 0 ? 220 : 350));

    return compact(
        'esExcel', 'esPDF', 'esDashboard', 'hojas', 'nTablas', 'profundo', 'investigar',
        'esCarta', 'objetivo', 'anio', 'anios', 'soloTabla'
    );
}


/* ==========================================================
   NORMALIZADOR DE ELEMENTOS DEL PDF
   ========================================================== */

function normalizarTabla(array $e): ?array
{
    $cols  = $e['columnas'] ?? $e['encabezados'] ?? $e['cabeceras']
          ?? $e['headers'] ?? $e['columns'] ?? [];
    $filas = $e['filas'] ?? $e['rows'] ?? $e['datos'] ?? $e['data']
          ?? $e['contenido'] ?? $e['valores'] ?? [];

    if (!is_array($filas) || !$filas) {
        return null;
    }

    $cols = array_map(function ($c) {
        if (is_array($c)) {
            return celdaATexto($c['nombre'] ?? $c['titulo'] ?? $c['name'] ?? reset($c));
        }
        return celdaATexto($c);
    }, is_array($cols) ? array_values($cols) : []);

    $primera = reset($filas);
    if (!$cols && is_array($primera) && $primera && array_keys($primera) !== range(0, count($primera) - 1)) {
        $cols = array_map('celdaATexto', array_keys($primera));
    }

    $filasOk = [];
    foreach ($filas as $fila) {
        if (!is_array($fila)) {
            $fila = [$fila];
        }
        $esAsociativa = $fila && array_keys($fila) !== range(0, count($fila) - 1);
        if ($esAsociativa && $cols) {
            $valores = [];
            foreach ($cols as $i => $nombre) {
                if (array_key_exists($nombre, $fila)) {
                    $valores[] = celdaATexto($fila[$nombre]);
                } else {
                    $valores[] = celdaATexto(array_values($fila)[$i] ?? '');
                }
            }
        } else {
            $valores = array_map('celdaATexto', array_values($fila));
        }
        $filasOk[] = $valores;
    }

    if (!$cols) {
        $n = max(array_map('count', $filasOk));
        $cols = array_map(fn($i) => 'Campo ' . ($i + 1), range(0, max($n, 1) - 1));
    }

    $n = count($cols);
    foreach ($filasOk as &$f) {
        $f = array_slice(array_pad($f, $n, ''), 0, $n);
    }
    unset($f);

    return ['tipo' => 'tabla', 'columnas' => $cols, 'filas' => $filasOk];
}


/* ==========================================================
   INVESTIGACIÓN WEB
   Cadena de buscadores, ninguno obligatorio:
   1) Ollama web_search (solo si hay clave)
   2) DuckDuckGo (html y lite)
   3) Wikipedia en inglés y español (API oficial, no bloquea)
   ========================================================== */

function registrarFalloBusqueda(string $proveedor, string $motivo, bool $caido = true): void
{
    global $ERRORES_BUSQUEDA, $PROVEEDORES_CAIDOS;
    $ERRORES_BUSQUEDA[$proveedor] = $motivo;
    if ($caido) {
        $PROVEEDORES_CAIDOS[$proveedor] = true;   // no se reintenta en esta petición
    }
    guardarDebug('búsqueda: ' . $proveedor, $motivo);
}

function motivoFalloBusqueda(): string
{
    global $ERRORES_BUSQUEDA;
    if (!$ERRORES_BUSQUEDA) {
        return 'ningún buscador devolvió resultados';
    }
    $p = [];
    foreach ($ERRORES_BUSQUEDA as $nombre => $motivo) {
        $p[] = $nombre . ': ' . $motivo;
    }
    return implode('; ', $p);
}

/**
 * Petición HTTP. Si falla la verificación SSL (muy común en XAMPP/Windows sin
 * certificados instalados) reintenta una vez sin verificar, solo para leer páginas públicas.
 */
function peticionHttp(string $url, ?array $post = null, array $headers = [], int $timeout = 15): array
{
    $verificarSsl = true;

    for ($i = 0; $i < 2; $i++) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_merge(['Accept-Language: es-ES,es;q=0.9,en;q=0.8'], $headers),
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        if (!$verificarSsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false && $verificarSsl && in_array($errno, [35, 51, 58, 60, 77], true)) {
            $verificarSsl = false;
            continue;
        }
        return ['body' => $body === false ? '' : (string)$body, 'http' => $http, 'error' => $err];
    }
    return ['body' => '', 'http' => 0, 'error' => 'error SSL'];
}

function htmlATexto(string $html): string
{
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }
    $html = preg_replace('#<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('#</t[dh]>#i', ' | ', $html);                       // celdas separadas
    $html = preg_replace('#</(tr|p|li|h[1-6]|div|caption)>|<br\s*/?>#i', "\n", $html);   // una fila por línea
    $t = strip_tags($html);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/\[\d+\]/', '', $t);
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/\s*\n\s*/', "\n", $t);
    return trim($t);
}

/**
 * Si el texto es largo, se queda con las líneas que mencionan los años pedidos
 * (más la anterior y la siguiente). Así una lista larga de lanzamientos no se pierde.
 */
function extraerRelevante(string $texto, array $anios, int $limite): string
{
    if (mb_strlen($texto) <= $limite) {
        return $texto;
    }
    if ($anios) {
        $lineas = explode("\n", $texto);
        $sel = [];
        foreach ($lineas as $i => $l) {
            foreach ($anios as $y) {
                if (str_contains($l, (string)$y)) {
                    foreach ([$i - 1, $i, $i + 1] as $j) {
                        if (isset($lineas[$j])) {
                            $sel[$j] = true;
                        }
                    }
                    break;
                }
            }
        }
        if ($sel) {
            ksort($sel);
            $out = '';
            foreach (array_keys($sel) as $j) {
                $out .= $lineas[$j] . "\n";
                if (mb_strlen($out) >= $limite) {
                    break;
                }
            }
            return mb_substr(trim($out), 0, $limite);
        }
    }
    return mb_substr($texto, 0, $limite);
}

function obtenerContenidoPagina(string $url, array $anios = [], int $limite = 1800): string
{
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    $r = peticionHttp($url, null, ['Accept: text/html,application/xhtml+xml'], 8);
    if ($r['body'] === '' || $r['http'] < 200 || $r['http'] >= 400 || strpos($r['body'], "\0") !== false) {
        return '';
    }
    return extraerRelevante(htmlATexto($r['body']), $anios, $limite);
}

function limpiarConsulta(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace(
        '/\b(genera(me)?|crea(me)?|haz(me)?|dame|quiero|necesito|por favor|una?|unos?|unas?|en|con|de|del|los|las|el|la|y|pdf|excel|tabla|tablas|lista|documento|archivo)\b/iu',
        ' ',
        $s
    );
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, 120);
}

/**
 * Convierte la solicitud en una consulta corta en español y otra en inglés
 * (Wikipedia en inglés tiene muchas más listas). Se calcula una sola vez.
 */
function planBusqueda(string $solicitud): array
{
    global $PLAN_BUSQUEDA;
    if ($PLAN_BUSQUEDA !== null) {
        return $PLAN_BUSQUEDA;
    }

    $base = limpiarConsulta($solicitud);
    $PLAN_BUSQUEDA = ['es' => $base, 'en' => $base];

    try {
        $prompt = <<<PROMPT
Convierte la solicitud en dos consultas cortas para un buscador (máximo 8 palabras cada una): una en español y otra en inglés.
Conserva los años, los nombres propios y el tema. No incluyas palabras como genera, tabla, pdf o excel. Responde solo con JSON.

EJEMPLO (otro tema): solicitud "hazme un pdf con los mejores restaurantes de Lima en 2025"
{"es":"mejores restaurantes Lima 2025","en":"best restaurants Lima 2025"}

SOLICITUD:
$solicitud
PROMPT;
        $schema = [
            'type' => 'object',
            'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']],
            'required' => ['es', 'en'],
        ];
        $d = pedirJson('consulta de búsqueda', $prompt, $schema, 120);
        foreach (['es', 'en'] as $k) {
            $v = mb_substr(celdaATexto($d[$k] ?? ''), 0, 120);
            if ($v !== '') {
                $PLAN_BUSQUEDA[$k] = $v;
            }
        }
    } catch (Throwable $e) {
        guardarDebug('planBusqueda', $e->getMessage());
    }

    // Que no se pierdan los años pedidos
    preg_match_all('/\b(20\d{2})\b/', $solicitud, $m);
    foreach (array_unique($m[1] ?? []) as $y) {
        foreach (['es', 'en'] as $k) {
            if (!str_contains($PLAN_BUSQUEDA[$k], $y)) {
                $PLAN_BUSQUEDA[$k] .= ' ' . $y;
            }
        }
    }
    return $PLAN_BUSQUEDA;
}

/* ---------- Proveedor 1: Ollama (opcional) ---------- */

function buscarOllamaWeb(string $consulta, int $max): array
{
    global $PROVEEDORES_CAIDOS;
    if (OLLAMA_API_KEY === '' || !empty($PROVEEDORES_CAIDOS['ollama'])) {
        return [];
    }

    $ch = curl_init('https://ollama.com/api/web_search');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OLLAMA_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode(['query' => $consulta, 'max_results' => $max], JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        registrarFalloBusqueda('ollama', 'sin conexión (' . $err . ')');
        return [];
    }
    if ($http === 401 || $http === 403) {
        registrarFalloBusqueda('ollama', 'clave rechazada (HTTP ' . $http . ')');
        return [];
    }
    if ($http < 200 || $http >= 300) {
        registrarFalloBusqueda('ollama', 'HTTP ' . $http);
        return [];
    }
    $d = json_decode($resp, true);
    return is_array($d['results'] ?? null) ? $d['results'] : [];
}

/* ---------- Proveedor 2: DuckDuckGo ---------- */

function limpiarUrlResultado(string $url): string
{
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    } elseif (str_starts_with($url, '/')) {
        $url = 'https://duckduckgo.com' . $url;
    }
    $p = parse_url($url);
    if (!empty($p['host']) && str_contains($p['host'], 'duckduckgo.com') && !empty($p['query'])) {
        parse_str($p['query'], $q);
        if (!empty($q['uddg'])) {
            return (string)$q['uddg'];
        }
    }
    return $url;
}

function parsearDuck(string $html, string $modo, int $max): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($dom);
    $res = [];

    $claseLink = $modo === 'html' ? 'result__a' : 'result-link';
    $claseSnip = $modo === 'html' ? 'result__snippet' : 'result-snippet';
    $enlaces = $xp->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' $claseLink ')]");

    foreach ($enlaces as $a) {
        if (count($res) >= $max) {
            break;
        }
        $href = limpiarUrlResultado($a->getAttribute('href'));
        if ($href === '' || str_contains($href, 'duckduckgo.com/y.js')) {   // anuncios
            continue;
        }
        $titulo = trim(preg_replace('/\s+/u', ' ', $a->textContent));
        if ($titulo === '') {
            continue;
        }

        // El resumen se busca dentro del bloque de ESTE resultado (así no se desalinea con los anuncios)
        $ruta = $modo === 'html'
            ? "./ancestor::div[contains(@class,'result')][1]//*[contains(concat(' ', normalize-space(@class), ' '), ' $claseSnip ')]"
            : "./ancestor::tr[1]/following-sibling::tr[1]//*[contains(concat(' ', normalize-space(@class), ' '), ' $claseSnip ')]";
        $nodo = $xp->query($ruta, $a)->item(0);
        $snippet = $nodo ? trim(preg_replace('/\s+/u', ' ', $nodo->textContent)) : '';

        $res[] = ['title' => $titulo, 'url' => $href, 'content' => $snippet];
    }
    return $res;
}

function buscarDuckDuckGo(string $q, int $max, array $anios): array
{
    global $PROVEEDORES_CAIDOS;

    $variantes = [
        ['duckduckgo', 'https://html.duckduckgo.com/html/', 'html'],
        ['duckduckgo-lite', 'https://lite.duckduckgo.com/lite/', 'lite'],
    ];

    foreach ($variantes as [$nombre, $url, $modo]) {
        if (!empty($PROVEEDORES_CAIDOS[$nombre])) {
            continue;
        }

        $r = peticionHttp($url, ['q' => $q, 'kl' => 'es-es'], ['Referer: https://duckduckgo.com/'], 15);

        if ($r['body'] === '') {
            registrarFalloBusqueda($nombre, 'falló (' . ($r['error'] ?: 'HTTP ' . $r['http']) . ')');
            continue;
        }
        if ($r['http'] === 202 || stripos($r['body'], 'anomaly') !== false) {
            registrarFalloBusqueda($nombre, 'bloqueó la consulta por anti-bots (HTTP ' . $r['http'] . ')');
            continue;
        }
        if ($r['http'] < 200 || $r['http'] >= 400) {
            registrarFalloBusqueda($nombre, 'HTTP ' . $r['http']);
            continue;
        }

        $items = parsearDuck($r['body'], $modo, $max);
        if (!$items) {
            registrarFalloBusqueda($nombre, 'respuesta sin resultados reconocibles', false);
            continue;
        }

        foreach ($items as $i => &$it) {
            if ($i < 2) {
                $c = obtenerContenidoPagina($it['url'], $anios, 1800);
                if ($c !== '') {
                    $it['content'] = $c;
                }
            }
        }
        unset($it);
        return $items;
    }
    return [];
}

/* ---------- Proveedor 3: Wikipedia ---------- */

function buscarWikipedia(string $q, int $max, string $lang, array $anios): array
{
    global $PROVEEDORES_CAIDOS;
    static $cacheWiki = [];
    $nombre = 'wikipedia-' . $lang;
    if (!empty($PROVEEDORES_CAIDOS[$nombre]) || trim($q) === '') {
        return [];
    }
    $claveWiki = $lang . '|' . $q . '|' . implode(',', $anios);
    if (isset($cacheWiki[$claveWiki])) {
        return $cacheWiki[$claveWiki];
    }

    $ua = ['User-Agent: GeneradorPDF/1.0 (script personal; PHP cURL)'];
    $url = 'https://' . $lang . '.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'list' => 'search', 'srsearch' => $q,
        'srlimit' => $max, 'format' => 'json', 'utf8' => 1,
    ]);
    $r = peticionHttp($url, null, $ua, 20);

    if ($r['body'] === '' || $r['http'] < 200 || $r['http'] >= 300) {
        registrarFalloBusqueda($nombre, 'falló (' . ($r['error'] ?: 'HTTP ' . $r['http']) . ')');
        return [];
    }

    $d = json_decode($r['body'], true);
    $hits = $d['query']['search'] ?? [];
    if (!$hits) {
        registrarFalloBusqueda($nombre, 'sin resultados para "' . $q . '"', false);
        return [];
    }

    $res = [];
    foreach (array_slice($hits, 0, $max) as $i => $h) {
        $titulo = (string)($h['title'] ?? '');
        if ($titulo === '') {
            continue;
        }
        $slug = rawurlencode(str_replace(' ', '_', $titulo));
        $contenido = '';

        if ($i < 2) {
            $p = peticionHttp('https://' . $lang . '.wikipedia.org/api/rest_v1/page/html/' . $slug, null, $ua, 20);
            if ($p['http'] >= 200 && $p['http'] < 300 && $p['body'] !== '') {
                $contenido = extraerRelevante(htmlATexto($p['body']), $anios, 1800);
            }
        }
        if ($contenido === '') {
            $contenido = trim(html_entity_decode(strip_tags((string)($h['snippet'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $res[] = [
            'title' => $titulo . ' (Wikipedia)',
            'url' => 'https://' . $lang . '.wikipedia.org/wiki/' . $slug,
            'content' => $contenido,
        ];
    }
    return $cacheWiki[$claveWiki] = $res;
}

/* ---------- Orquestador ---------- */

function buscarWeb(string $consulta, int $max = 5, array $anios = []): array
{
    static $cache = [];
    $clave = md5($consulta . '|' . $max . '|' . implode(',', $anios));
    if (isset($cache[$clave])) {
        return $cache[$clave];
    }

    $plan = planBusqueda($GLOBALS['SOLICITUD_ACTUAL'] ?? $consulta);

    $res = buscarWikipedia($plan['en'], 2, 'en', $anios);          // listas y fechas, fiable
    $res = array_merge($res, buscarOllamaWeb($consulta, $max));     // si hay clave
    if (count($res) < 4) {
        $res = array_merge($res, buscarDuckDuckGo($consulta, $max, $anios));
    }
    if (count($res) < 3) {
        $res = array_merge($res, buscarWikipedia($plan['es'], 3, 'es', $anios));
    }

    $vistos = [];
    $unicos = [];
    foreach ($res as $r) {
        $u = (string)($r['url'] ?? '');
        if ($u !== '' && !isset($vistos[$u])) {
            $vistos[$u] = true;
            $unicos[] = $r;
        }
    }

    guardarDebug('búsqueda web: ' . $consulta, count($unicos) . ' resultados');
    return $cache[$clave] = $unicos;
}

function formatearFuentes(array $resultados): string
{
    global $FUENTES;
    $txt = '';
    $n = 0;
    $vistos = [];
    foreach ($resultados as $r) {
        if ($n >= 5) {
            break;
        }
        $url = (string)($r['url'] ?? '');
        if ($url !== '' && isset($vistos[$url])) {
            continue;
        }
        $vistos[$url] = true;
        $titulo = (string)($r['title'] ?? $url);
        $contenido = mb_substr(trim((string)($r['content'] ?? '')), 0, 1800);
        if ($contenido === '') {
            continue;
        }
        $n++;
        if ($url !== '') {
            $FUENTES[$url] = $titulo;
        }
        $txt .= '[' . $n . '] ' . $titulo . ' (' . $url . ")\n" . $contenido . "\n\n";
    }
    return trim($txt);
}

// Si la solicitud menciona años, busca cada año por separado y une los resultados.
function fuentesPara(string $consulta, bool $investigar, array $anios = []): string
{
    if (!$investigar) {
        return '';
    }

    $res = [];
    if ($anios) {
        $base = trim(preg_replace('/\s+/', ' ', preg_replace('/\b20\d{2}\b/', '', $consulta)));
        foreach ($anios as $y) {
            $r = buscarWeb(mb_substr("$base $y", 0, 150), 6, $anios);
            $res = array_merge($res, array_slice($r, 0, 5));
        }
    } else {
        $res = buscarWeb(mb_substr($consulta, 0, 150), 6);
    }
    return formatearFuentes($res);
}

function reglaFuentes(string $fuentes): string
{
    if ($fuentes !== '') {
        return "Usa SOLO la información de las FUENTES de abajo. Si un dato no aparece en las fuentes, no lo inventes.\n\nFUENTES:\n" . $fuentes;
    }
    return 'Usa solo información que conozcas con seguridad. No inventes nombres, direcciones, teléfonos ni horarios.';
}


/* ==========================================================
   LLAMADA A OLLAMA
   ========================================================== */

/**
 * Devuelve el modelo a usar: el preferido si está instalado; si no, otro instalado.
 * Evita el error HTTP 404 de Ollama cuando el modelo no se ha descargado.
 */
function modeloOllama(): string
{
    static $modelo = null;
    if ($modelo !== null) {
        return $modelo;
    }
    $modelo = OLLAMA_MODELO;

    $ch = curl_init('http://localhost:11434/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return $modelo;
    }

    $d = json_decode($resp, true);
    $instalados = [];
    foreach ((array)($d['models'] ?? []) as $m) {
        $n = (string)($m['name'] ?? '');
        if ($n !== '' && stripos($n, 'embed') === false) {
            $instalados[] = $n;
        }
    }
    if (!$instalados) {
        return $modelo;
    }

    foreach ([OLLAMA_MODELO, 'qwen2.5:7b', 'qwen2.5:3b', 'llama3.1:8b', 'llama3.2:3b'] as $pref) {
        foreach ($instalados as $n) {
            if ($n === $pref || $n === $pref . ':latest') {
                guardarDebug('modelo', 'usando ' . $n);
                return $modelo = $n;
            }
        }
    }
    guardarDebug('modelo', 'usando ' . $instalados[0] . ' (el preferido no está instalado)');
    return $modelo = $instalados[0];
}


function llamarOllama(string $prompt, array $schema, int $numPredict): string
{
    $ultimoError = 'Error desconocido con Ollama.';

    foreach ([$schema, 'json'] as $formato) {
        $conexionFallo = false;

        for ($intento = 1; $intento <= 2; $intento++) {
            $cuerpo = [
                'model' => modeloOllama(),
                'prompt' => $prompt,
                'stream' => false,
                'format' => $formato,
                'keep_alive' => '30m',          // mantiene el modelo cargado en memoria
                'options' => [
                    'temperature' => 0.3,
                    'num_ctx' => 8192,
                    'num_predict' => $numPredict,
                ],
            ];

            $ch = curl_init('http://localhost:11434/api/generate');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 240,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false || $err !== '') {
                $ultimoError = 'No pude conectarme con Ollama o tardó demasiado. Verifica que Ollama esté ejecutándose.';
                $conexionFallo = true;
                guardarDebug('conexion Ollama FALLÓ', "intento=$intento error=$err");
                sleep(2);
                continue;
            }

            $conexionFallo = false;
            if ($http >= 200 && $http < 300) {
                $r = json_decode($resp, true);
                if (is_array($r)) {
                    return trim($r['response'] ?? '');
                }
                $ultimoError = 'La respuesta de Ollama no fue válida.';
            } else {
                $detalle = json_decode((string)$resp, true);
                $ultimoError = 'Ollama respondió con HTTP ' . $http;
                if ($http === 404) {
                    $ultimoError .= ': el modelo "' . modeloOllama() . '" no está instalado. Ejecuta en una terminal: ollama pull ' . modeloOllama()
                        . ' (o cambia OLLAMA_MODELO por uno que tengas con: ollama list).';
                } elseif (is_array($detalle) && !empty($detalle['error'])) {
                    $ultimoError .= ': ' . $detalle['error'];
                }
            }
            break;   // error HTTP: probar con el formato siguiente
        }

        if ($conexionFallo) {
            break;   // sin conexión: no tiene sentido probar otro formato
        }
    }

    throw new RuntimeException($ultimoError);
}

/**
 * Pide un JSON al modelo y lo valida. Si falla, reintenta una vez indicando los errores.
 */
function pedirJson(string $etiqueta, string $prompt, array $schema, int $numPredict, ?callable $validar = null): array
{
    $errores = [];
    $ultimo = [];

    for ($i = 1; $i <= 2; $i++) {
        latido();
        $p = $prompt;
        if ($errores) {
            $p .= "\n\nTU RESPUESTA ANTERIOR TENÍA ESTOS PROBLEMAS. CORRÍGELOS:\n- "
                . implode("\n- ", $errores) . "\nDevuelve el JSON completo y corregido.";
        }

        $raw = limpiarJson(llamarOllama($p, $schema, $numPredict));
        guardarDebug($etiqueta . ' (intento ' . $i . ')', $raw);

        $d = json_decode($raw, true);
        if (!is_array($d)) {
            $errores = ['El JSON no era válido. Devuelve solo JSON válido y completo.'];
            continue;
        }

        $ultimo = $d;
        $errores = $validar ? $validar($d) : [];
        if (!$errores) {
            return $d;
        }
    }
    return $ultimo;
}


/* ==========================================================
   GENERAR PDF POR SECCIONES
   ========================================================== */

function generarCarta(string $solicitud, array $a): array
{
    $objetivo = $a['hojas'] > 0 ? $a['objetivo'] : ($a['profundo'] ? 450 : 220);
    $nPar = max(3, (int)round($objetivo / 90));

    $prompt = <<<PROMPT
Escribe una carta o mensaje personal ya terminado, como si el propio usuario lo hubiera escrito. Responde solo con JSON.

REGLAS:
- La solicitud es una instrucción para ti. Las órdenes como "dile", "recuérdale", "genera" NUNCA aparecen en el texto: conviértelas en frases directas.
- Escribe en primera persona (yo) y háblale de tú al destinatario. Nunca hables de él o ella en tercera persona.
- Incluye todas las ideas del usuario con redacción natural y palabras propias, sin copiar frases textuales.
- No agregues ideas, sentimientos ni hechos que el usuario no dijo.
- Estructura: saludo con el nombre del destinatario si lo dio, desarrollo y cierre.
- Total: unas $objetivo palabras en $nPar párrafos. Solo texto, sin tablas ni listas.
- El título es breve y específico (por ejemplo "Carta para" seguido del nombre del destinatario si lo dio).

SOLICITUD DEL USUARIO:
$solicitud
PROMPT;

    $schema = [
        'type' => 'object',
        'properties' => [
            'titulo' => ['type' => 'string'],
            'parrafos' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['titulo', 'parrafos'],
    ];

    $validar = function (array $d) use ($nPar, $objetivo): array {
        $pars = array_filter(array_map('celdaATexto', (array)($d['parrafos'] ?? [])));
        $pal = 0;
        foreach ($pars as $p) {
            $pal += contarPalabras($p);
        }
        $e = [];
        if (count($pars) < 3) {
            $e[] = 'Escribe al menos 3 párrafos: saludo, desarrollo y cierre.';
        }
        if ($pal < $objetivo * 0.6) {
            $e[] = "El texto tiene $pal palabras y se necesitan unas $objetivo. Desarrolla más las mismas ideas.";
        }
        return $e;
    };

    $d = pedirJson('carta', $prompt, $schema, 1800, $validar);

    $elementos = [];
    foreach ((array)($d['parrafos'] ?? []) as $p) {
        $p = celdaATexto($p);
        if ($p !== '') {
            $elementos[] = ['tipo' => 'parrafo', 'texto' => $p];
        }
    }
    return [
        'titulo' => celdaATexto($d['titulo'] ?? '') ?: 'Carta',
        'elementos' => $elementos,
    ];
}

function generarPdf(string $solicitud, array $a): array
{
    if ($a['esCarta']) {
        return generarCarta($solicitud, $a);
    }

    $investigar = $a['investigar'];
    $N = $a['nTablas'];
    $objetivo = $a['objetivo'];
    $T = max(2, min(12, (int)ceil($objetivo / 170)));
    if ($a['soloTabla']) {
        $T = 2;   // los textos no se usan, solo se necesita un plan válido
    }
    $nFilas = $a['profundo'] ? 10 : 6;
    if ($a['soloTabla']) {
        $nFilas = 8;
    }
    $minFilas = $investigar ? 3 : 5;
    $nPar = max(1, (int)round(($objetivo / $T) / 90));

    /* ---------- 1) PLANIFICAR ---------- */
    // Una sola tabla: no hace falta gastar una llamada al modelo en planear el título.
    $planSimple = $a['soloTabla'] && $N === 1;

    if ($planSimple) {
        $tit = $investigar ? planBusqueda($solicitud)['es'] : limpiarConsulta($solicitud);
        $tit = mb_substr(trim($tit) !== '' ? trim($tit) : 'Tabla', 0, 80);
        $doc = mb_strtoupper(mb_substr($tit, 0, 1)) . mb_substr($tit, 1);
        $textos = ['', ''];
        $tablas = [$doc];
    } else {
    $ejemploPlan = '{"titulo":"Frutas tropicales","textos":["Qué son las frutas tropicales","Cómo se cultivan","Beneficios para la salud"],"tablas":["Frutas más conocidas","Aporte nutricional"]}';

    $promptPlan = <<<PROMPT
Planifica un documento PDF según la solicitud del usuario. Responde solo con JSON.

Devuelve:
- "titulo": un título real y específico del documento.
- "textos": exactamente $T títulos de secciones de texto. El primero es la introducción y el último la conclusión.
- "tablas": exactamente $N títulos de tablas. Cada tabla cubre una categoría o un aspecto distinto del tema (por ejemplo tipos de lugares, categorías o características).

Los títulos deben ser reales, cortos y específicos del tema de la solicitud.

EJEMPLO DE ESTRUCTURA con otro tema (NO copies su contenido):
$ejemploPlan

SOLICITUD DEL USUARIO:
$solicitud
PROMPT;

    $schemaPlan = [
        'type' => 'object',
        'properties' => [
            'titulo' => ['type' => 'string'],
            'textos' => ['type' => 'array', 'items' => ['type' => 'string']],
            'tablas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['titulo', 'textos', 'tablas'],
    ];

    $valPlan = function (array $d) use ($T, $N): array {
        $e = [];
        if (trim((string)($d['titulo'] ?? '')) === '') {
            $e[] = 'Falta el título.';
        }
        if (count((array)($d['textos'] ?? [])) !== $T) {
            $e[] = "Debe haber exactamente $T títulos en \"textos\".";
        }
        if (count((array)($d['tablas'] ?? [])) !== $N) {
            $e[] = "Debe haber exactamente $N títulos en \"tablas\".";
        }
        return $e;
    };

    $plan = pedirJson('plan', $promptPlan, $schemaPlan, 500, $valPlan);

    $doc = celdaATexto($plan['titulo'] ?? '') ?: mb_substr($solicitud, 0, 70);
    $textos = array_values(array_filter(array_map('celdaATexto', (array)($plan['textos'] ?? []))));
    $tablas = array_values(array_filter(array_map('celdaATexto', (array)($plan['tablas'] ?? []))));

    $genTexto = ['Contexto general', 'Aspectos principales', 'Detalles importantes', 'Datos relevantes',
                 'Puntos clave', 'Panorama actual', 'Consideraciones finales', 'Más sobre el tema'];
    $genTabla = ['Datos principales', 'Más información', 'Otros datos relevantes', 'Resumen comparativo',
                 'Información adicional', 'Casos destacados'];
    for ($i = 0; count($textos) < $T; $i++) {
        $textos[] = $genTexto[$i % count($genTexto)];
    }
    for ($i = 0; count($tablas) < $N; $i++) {
        $tablas[] = $genTabla[$i % count($genTabla)];
    }
    $textos = array_slice($textos, 0, $T);
    $tablas = array_slice($tablas, 0, $N);
    }

    /* ---------- 2) ORDENAR SECCIONES ---------- */
    $orden = [];
    if ($a['soloTabla']) {
        // Solo tablas: sin introducción, desarrollo ni conclusión
        foreach ($tablas as $t) {
            $orden[] = ['tabla', $t, ''];
        }
    } else {
        $orden[] = ['texto', $textos[0], 'intro'];
        $cuerpo = array_slice($textos, 1, max(0, $T - 2));
        $maxK = max(count($cuerpo), $N);
        for ($k = 0; $k < $maxK; $k++) {
            if (isset($cuerpo[$k])) {
                $orden[] = ['texto', $cuerpo[$k], 'desarrollo'];
            }
            if (isset($tablas[$k])) {
                $orden[] = ['tabla', $tablas[$k], ''];
            }
        }
        $orden[] = ['texto', $textos[$T - 1], 'cierre'];
    }

    /* ---------- 3) GENERAR CADA SECCIÓN ---------- */
    $roles = [
        'intro' => 'introducción que presenta el tema',
        'desarrollo' => 'sección de desarrollo con información concreta',
        'cierre' => 'conclusión que cierra resumiendo las ideas principales',
    ];

    $schemaTexto = [
        'type' => 'object',
        'properties' => ['parrafos' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'required' => ['parrafos'],
    ];
    $schemaTabla = [
        'type' => 'object',
        'properties' => [
            'columnas' => ['type' => 'array', 'items' => ['type' => 'string']],
            'filas' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ],
        'required' => ['columnas', 'filas'],
    ];

    $elementos = [];

    // Se busca una sola vez por documento (antes se repetía en cada sección y era muy lento).
    $fuentes = '';
    if ($investigar) {
        $fuentes = fuentesPara(planBusqueda($solicitud)['es'], true, $a['anios']);
        if ($fuentes === '' && $a['anio'] >= 2025) {
            throw new RuntimeException(
                'No pude obtener datos de ' . implode(' y ', $a['anios']) . ' desde internet (' . motivoFalloBusqueda() . '). '
                . 'El modelo local no conoce ese año y para no inventar información no generé el documento.'
            );
        }
    }

    foreach ($orden as [$kind, $titulo, $rol]) {
        latido();
        $regla = reglaFuentes($fuentes);

        if ($kind === 'texto') {
            $descripcion = $roles[$rol];
            $promptSec = <<<PROMPT
Documento: "$doc"
Solicitud original del usuario: $solicitud

Escribe SOLO la sección "$titulo" ($descripcion).
Redacta $nPar párrafo(s) de unas 90 palabras cada uno, en español, con información concreta, sin saludos ni despedidas y sin repetir el título.
$regla

Estructura: {"parrafos":["texto del primer párrafo","texto del segundo párrafo"]}
PROMPT;

            $valTexto = function (array $d) use ($nPar): array {
                $pars = array_filter(array_map('celdaATexto', (array)($d['parrafos'] ?? [])));
                $pal = 0;
                foreach ($pars as $p) {
                    $pal += contarPalabras($p);
                }
                if (!$pars) {
                    return ['No escribiste ningún párrafo.'];
                }
                if ($pal < $nPar * 55) {
                    return ["El texto es muy corto. Escribe $nPar párrafo(s) de unas 90 palabras cada uno."];
                }
                return [];
            };

            $d = pedirJson('texto: ' . $titulo, $promptSec, $schemaTexto, 800, $valTexto);

            $pars = array_values(array_filter(array_map('celdaATexto', (array)($d['parrafos'] ?? []))));
            if ($rol !== 'intro') {
                $elementos[] = ['tipo' => 'subtitulo', 'texto' => $titulo];
            }
            foreach ($pars as $p) {
                $elementos[] = ['tipo' => 'parrafo', 'texto' => $p];
            }
        } else {
            $extra = $fuentes !== ''
                ? 'Para lugares, negocios, personas o productos usa solo nombres que aparezcan en las fuentes. Si las fuentes dan pocos elementos, entrega menos filas (mínimo 3) en vez de inventar.'
                : '';

            $reglaAnio = '';
            if ($a['anios']) {
                $listaAnios = implode(' y ', $a['anios']);
                $reglaAnio = "- Incluye solo elementos de $listaAnios. No incluyas otros años.\n"
                    . "- En la columna de fecha escribe siempre el año. Si la fecha no es exacta, escribe el año y el estado (por ejemplo \"2027 (fecha por confirmar)\").";
            }

            $promptTabla = <<<PROMPT
Documento: "$doc"
Solicitud original del usuario: $solicitud

Crea SOLO la tabla "$titulo".
- Entre 3 y 4 columnas útiles y $nFilas filas con elementos reales y distintos entre sí.
- Todas las celdas con texto real; nunca dejes una celda vacía.
$reglaAnio
- $regla
$extra

EJEMPLO DE ESTRUCTURA con otro tema (NO copies su contenido):
{"columnas":["Fruta","Sabor","Vitamina"],"filas":[["Mango","Dulce","A"],["Piña","Dulce y ácido","C"]]}
PROMPT;

            $valTabla = function (array $d) use ($minFilas, $a): array {
                $t = normalizarTabla($d);
                if (!$t) {
                    return ['La tabla no tiene filas.'];
                }
                $e = [];
                foreach ($t['filas'] as $fila) {
                    foreach ($fila as $celda) {
                        if ($celda === '') {
                            $e[] = 'Hay celdas vacías. Completa TODAS las celdas con información real.';
                            break 2;
                        }
                    }
                }
                if (count($t['filas']) < $minFilas) {
                    $e[] = "La tabla tiene muy pocas filas. Escribe al menos $minFilas filas.";
                }
                if (!empty($a['anios'])) {
                    $ok = 0;
                    foreach ($t['filas'] as $fila) {
                        $txt = implode(' ', $fila);
                        foreach ($a['anios'] as $y) {
                            if (str_contains($txt, (string)$y)) {
                                $ok++;
                                break;
                            }
                        }
                    }
                    if ($ok < count($t['filas']) / 2) {
                        $e[] = 'Las filas deben ser de los años ' . implode(' y ', $a['anios'])
                            . ' y la fecha debe incluir el año. Quita las de otros años.';
                    }
                }
                return $e;
            };

            $d = pedirJson('tabla: ' . $titulo, $promptTabla, $schemaTabla, 1100, $valTabla);

            if (!$planSimple) {
                $elementos[] = ['tipo' => 'subtitulo', 'texto' => $titulo];
            }
            $t = normalizarTabla($d);
            if ($t) {
                foreach ($t['filas'] as &$fila) {
                    foreach ($fila as &$celda) {
                        if ($celda === '') {
                            $celda = '-';
                        }
                    }
                    unset($celda);
                }
                unset($fila);
                $elementos[] = $t;
            } else {
                $elementos[] = ['tipo' => 'parrafo', 'texto' => 'No se pudo generar la tabla "' . $titulo . '". Intenta de nuevo.'];
            }
        }
    }

    return ['titulo' => $doc, 'elementos' => $elementos];
}


/* ==========================================================
   EXCEL Y RESPUESTA NORMAL
   ========================================================== */

function generarExcel(string $solicitud, array $a): array
{
    $investigar = $a['investigar'];
    $fuentes = $investigar ? fuentesPara(planBusqueda($solicitud)['es'], true, $a['anios']) : '';
    $regla = reglaFuentes($fuentes);
    $filas = $a['profundo'] ? 20 : 10;

    $prompt = <<<PROMPT
Crea los datos de una hoja de Excel según la solicitud del usuario. Responde solo con JSON.

REGLAS:
- Columnas reales y unas $filas filas reales en español, relacionadas con la solicitud.
- Cada fila tiene el mismo número de valores que columnas y ninguna celda vacía.
- $regla

EJEMPLO DE ESTRUCTURA con otro tema (NO copies su contenido):
{"titulo":"Frutas tropicales","columnas":["Fruta","Sabor","Vitamina"],"datos":[["Mango","Dulce","Vitamina A"],["Piña","Dulce y ácido","Vitamina C"]]}

SOLICITUD DEL USUARIO:
$solicitud
PROMPT;

    $schema = [
        'type' => 'object',
        'properties' => [
            'titulo' => ['type' => 'string'],
            'columnas' => ['type' => 'array', 'items' => ['type' => 'string']],
            'datos' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ],
        'required' => ['titulo', 'columnas', 'datos'],
    ];

    $validar = function (array $d): array {
        if (count((array)($d['columnas'] ?? [])) < 2 || count((array)($d['datos'] ?? [])) < 3) {
            return ['Faltan columnas o filas. Completa la tabla con información real.'];
        }
        return [];
    };

    $d = pedirJson('excel', $prompt, $schema, 3000, $validar);

    $columnas = array_map('celdaATexto', (array)($d['columnas'] ?? []));
    $datos = is_array($d['datos'] ?? null) ? $d['datos'] : [];
    if (!$columnas) {
        $columnas = ['Tema', 'Descripción'];
    }
    if (!$datos) {
        $datos = [[$d['titulo'] ?? 'Información', $solicitud]];
    }

    $n = count($columnas);
    $datos = array_map(
        fn($f) => array_slice(array_pad(array_map('celdaATexto', (array)$f), $n, '-'), 0, $n),
        $datos
    );

    return [
        'titulo' => celdaATexto($d['titulo'] ?? '') ?: 'Excel',
        'columnas' => $columnas,
        'datos' => $datos,
    ];
}

function generarRespuesta(string $solicitud, array $a): string
{
    global $FUENTES;
    $investigar = $a['investigar'];
    $fuentes = $investigar ? fuentesPara(planBusqueda($solicitud)['es'], true, $a['anios']) : '';
    $regla = reglaFuentes($fuentes);

    $prompt = <<<PROMPT
Responde en español a lo que pide el usuario, de forma completa y clara. Responde solo con JSON.
$regla

Estructura: {"respuesta":"tu respuesta completa aquí"}

SOLICITUD DEL USUARIO:
$solicitud
PROMPT;

    $schema = [
        'type' => 'object',
        'properties' => ['respuesta' => ['type' => 'string']],
        'required' => ['respuesta'],
    ];

    $validar = fn(array $d): array => trim((string)($d['respuesta'] ?? '')) === '' ? ['La respuesta quedó vacía.'] : [];

    $d = pedirJson('respuesta', $prompt, $schema, 2000, $validar);
    $texto = celdaATexto($d['respuesta'] ?? '');
    if ($texto === '') {
        return 'No pude generar una respuesta.';
    }

    if ($investigar && $FUENTES) {
        $texto .= "\n\nFuentes consultadas:";
        foreach (array_slice($FUENTES, 0, 6, true) as $url => $titulo) {
            $texto .= "\n- " . $titulo . ': ' . $url;
        }
    }
    return $texto;
}


/* ==========================================================
   DASHBOARD (KPIs + gráficos)
   Devuelve datos; el frontend los dibuja (ver comentario al final).
   ========================================================== */

function generarDashboard(string $solicitud, array $a): array
{
    global $FUENTES;
    $investigar = $a['investigar'];
    $fuentes = $investigar ? fuentesPara(planBusqueda($solicitud)['es'], true, $a['anios']) : '';
    $regla = reglaFuentes($fuentes);

    $prompt = <<<PROMPT
Crea los datos de un dashboard según la solicitud del usuario. Responde solo con JSON.

REGLAS:
- "titulo": título específico del dashboard.
- "kpis": 4 indicadores. Cada uno tiene "etiqueta" (texto corto) y "valor" (número).
- "graficos": 3 gráficos. "tipo" es "bar", "line" o "pie". Cada uno tiene "titulo", "etiquetas" (5 a 8 textos distintos) y "valores" (números, exactamente la misma cantidad que etiquetas).
- Los valores deben ser solo números, sin texto, símbolos ni unidades dentro del número.
- Los datos deben estar relacionados con el tema de la solicitud.
- $regla

EJEMPLO DE ESTRUCTURA con otro tema (NO copies su contenido):
{"titulo":"Ventas de frutas","kpis":[{"etiqueta":"Total ventas","valor":1200},{"etiqueta":"Clientes","valor":85}],"graficos":[{"tipo":"bar","titulo":"Ventas por fruta","etiquetas":["Mango","Piña","Papaya"],"valores":[500,300,200]}]}

SOLICITUD DEL USUARIO:
$solicitud
PROMPT;

    $schema = [
        'type' => 'object',
        'properties' => [
            'titulo' => ['type' => 'string'],
            'kpis' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'etiqueta' => ['type' => 'string'],
                        'valor' => ['type' => 'number'],
                    ],
                    'required' => ['etiqueta', 'valor'],
                ],
            ],
            'graficos' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'tipo' => ['type' => 'string'],
                        'titulo' => ['type' => 'string'],
                        'etiquetas' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'valores' => ['type' => 'array', 'items' => ['type' => 'number']],
                    ],
                    'required' => ['tipo', 'titulo', 'etiquetas', 'valores'],
                ],
            ],
        ],
        'required' => ['titulo', 'kpis', 'graficos'],
    ];

    $validar = function (array $d): array {
        $e = [];
        if (count((array)($d['kpis'] ?? [])) < 2) {
            $e[] = 'Faltan indicadores (kpis). Escribe 4.';
        }
        if (count((array)($d['graficos'] ?? [])) < 1) {
            $e[] = 'Faltan gráficos. Escribe 3.';
        }
        foreach ((array)($d['graficos'] ?? []) as $g) {
            if (!is_array($g)) {
                continue;
            }
            if (count((array)($g['etiquetas'] ?? [])) !== count((array)($g['valores'] ?? []))) {
                $e[] = 'En cada gráfico, "etiquetas" y "valores" deben tener la misma cantidad de elementos.';
                break;
            }
        }
        return $e;
    };

    $d = pedirJson('dashboard', $prompt, $schema, 2500, $validar);

    $graficos = [];
    foreach ((array)($d['graficos'] ?? []) as $g) {
        if (!is_array($g)) {
            continue;
        }
        $tipo = in_array($g['tipo'] ?? '', ['bar', 'line', 'pie'], true) ? $g['tipo'] : 'bar';
        $et = array_values(array_map('celdaATexto', (array)($g['etiquetas'] ?? [])));
        $va = array_values(array_map(fn($v) => (float)(is_array($v) ? 0 : $v), (array)($g['valores'] ?? [])));
        $n = min(count($et), count($va));
        if ($n > 0) {
            $graficos[] = [
                'tipo' => $tipo,
                'titulo' => celdaATexto($g['titulo'] ?? ''),
                'etiquetas' => array_slice($et, 0, $n),
                'valores' => array_slice($va, 0, $n),
            ];
        }
    }

    $kpis = [];
    foreach ((array)($d['kpis'] ?? []) as $k) {
        if (!is_array($k)) {
            continue;
        }
        $kpis[] = [
            'etiqueta' => celdaATexto($k['etiqueta'] ?? ''),
            'valor' => (float)($k['valor'] ?? 0),
        ];
    }

    if (!$graficos) {
        throw new RuntimeException('No pude generar los gráficos del dashboard. Intenta de nuevo.');
    }

    $fuentesLista = [];
    if ($investigar && $FUENTES) {
        foreach (array_slice($FUENTES, 0, 6, true) as $url => $titulo) {
            $fuentesLista[] = $titulo . ' - ' . $url;
        }
    }

    return [
        'titulo' => celdaATexto($d['titulo'] ?? '') ?: 'Dashboard',
        'kpis' => $kpis,
        'graficos' => $graficos,
        'fuentes' => $fuentesLista,
    ];
}


/**
 * Crea un archivo HTML con el dashboard dibujado (KPIs + gráficos) en la carpeta dashboards/
 * y devuelve su URL. No depende del frontend. Necesita internet en el navegador solo para Chart.js.
 */
function guardarDashboardHtml(array $dash): string
{
    $dir = __DIR__ . '/dashboards';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        guardarDebug('dashboard html', 'no pude crear la carpeta dashboards/');
        return '';
    }

    $nombre = 'dashboard_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.html';
    $json = json_encode(
        ['titulo' => $dash['titulo'], 'kpis' => $dash['kpis'], 'graficos' => $dash['graficos'], 'fuentes' => $dash['fuentes'] ?? []],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $titulo = htmlspecialchars((string)$dash['titulo'], ENT_QUOTES, 'UTF-8');

    $html = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>__TITULO__</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; background: #f4f6fa; color: #1f2937; }
  main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
  h1 { margin: 0 0 20px; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
  .kpi { background: #fff; border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .kpi small { color: #64748b; }
  .kpi div { font-size: 30px; font-weight: 700; margin-top: 4px; }
  .graficos { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; }
  .grafico { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .fuentes { margin-top: 20px; font-size: 13px; color: #64748b; }
</style>
</head>
<body>
<main>
  <h1 id="titulo"></h1>
  <div class="kpis" id="kpis"></div>
  <div class="graficos" id="graficos"></div>
  <div class="fuentes" id="fuentes"></div>
</main>
<script>
const D = __DATOS__;
const COLORES = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

document.getElementById('titulo').textContent = D.titulo;

D.kpis.forEach(k => {
  const el = document.createElement('div');
  el.className = 'kpi';
  const s = document.createElement('small'); s.textContent = k.etiqueta;
  const v = document.createElement('div'); v.textContent = Number(k.valor).toLocaleString('es');
  el.append(s, v);
  document.getElementById('kpis').appendChild(el);
});

D.graficos.forEach(g => {
  const caja = document.createElement('div');
  caja.className = 'grafico';
  const canvas = document.createElement('canvas');
  caja.appendChild(canvas);
  document.getElementById('graficos').appendChild(caja);
  const esPie = g.tipo === 'pie';
  new Chart(canvas, {
    type: g.tipo,
    data: {
      labels: g.etiquetas,
      datasets: [{
        label: g.titulo,
        data: g.valores,
        backgroundColor: (esPie || g.tipo === 'bar') ? g.etiquetas.map((_, j) => COLORES[j % COLORES.length]) : 'rgba(37,99,235,.15)',
        borderColor: g.tipo === 'line' ? '#2563eb' : undefined,
        fill: g.tipo === 'line',
        tension: 0.3
      }]
    },
    options: { plugins: { title: { display: true, text: g.titulo }, legend: { display: esPie } } }
  });
});

if (D.fuentes && D.fuentes.length) {
  document.getElementById('fuentes').textContent = 'Fuentes: ' + D.fuentes.join(' | ');
}
</script>
</body>
</html>
HTML;

    $html = str_replace(['__TITULO__', '__DATOS__'], [$titulo, $json], $html);

    if (@file_put_contents($dir . '/' . $nombre, $html) === false) {
        guardarDebug('dashboard html', 'no pude escribir el archivo');
        return '';
    }

    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $esquema . '://' . $host . $base . '/dashboards/' . $nombre;
}


/* ==========================================================
   FLUJO PRINCIPAL
   ========================================================== */

header('Content-Type: application/json; charset=utf-8');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

file_put_contents(__DIR__ . '/debug_ultima_respuesta.json', '');   // limpiar el log

$entrada = file_get_contents('php://input');
$datos = json_decode($entrada, true);
$solicitud = trim($datos['solicitud'] ?? '');

if ($solicitud === '') {
    salir(['tipo' => 'respuesta', 'respuesta' => 'No recibí ninguna solicitud.']);
}

$SOLICITUD_ACTUAL = $solicitud;
$a = analizarSolicitud($solicitud);
$tipoArchivo = $a['esDashboard'] ? 'dashboard' : ($a['esPDF'] ? 'pdf' : ($a['esExcel'] ? 'excel' : 'respuesta'));
$investigarEfectivo = $a['investigar'];

try {
    if ($tipoArchivo === 'dashboard') {
        $dash = generarDashboard($solicitud, $a);
        // Resumen en texto: si el frontend aún no dibuja dashboards, al menos muestra los datos
        $urlDash = guardarDashboardHtml($dash);
        $resumen = $urlDash !== ''
            ? "DASHBOARD GENERADO. Ábrelo en el navegador:\n" . $urlDash . "\n\n" . $dash['titulo'] . "\n\nIndicadores:"
            : $dash['titulo'] . "\n\nIndicadores:";
        foreach ($dash['kpis'] as $k) {
            $resumen .= "\n- " . $k['etiqueta'] . ': ' . $k['valor'];
        }
        foreach ($dash['graficos'] as $g) {
            $resumen .= "\n\n" . $g['titulo'] . ' (' . $g['tipo'] . '):';
            foreach ($g['etiquetas'] as $i => $et) {
                $resumen .= "\n- " . $et . ': ' . $g['valores'][$i];
            }
        }
        salir(['tipo' => 'dashboard', 'respuesta' => $resumen, 'url' => $urlDash] + $dash);
    }

    if ($tipoArchivo === 'pdf') {
        $doc = generarPdf($solicitud, $a);
        $elementos = $doc['elementos'];

        if (!$a['esCarta']) {
            if ($investigarEfectivo && $FUENTES) {
                $items = [];
                foreach (array_slice($FUENTES, 0, 8, true) as $url => $titulo) {
                    $items[] = $titulo . ' - ' . $url;
                }
                $elementos[] = ['tipo' => 'subtitulo', 'texto' => 'Fuentes consultadas'];
                $elementos[] = ['tipo' => 'lista', 'items' => $items];
            } elseif ($a['investigar']) {
                // Se pidió investigar pero no hubo fuentes: aviso corto y neutro
                $elementos[] = ['tipo' => 'parrafo', 'texto' => 'Información de referencia. Confirma las fechas en fuentes oficiales.'];
            }
        }

        if (!$elementos) {
            $elementos = [['tipo' => 'parrafo', 'texto' => 'No se pudo generar el contenido. Intenta de nuevo.']];
        }

        salir(['tipo' => 'pdf', 'titulo' => $doc['titulo'], 'elementos' => $elementos]);
    }

    if ($tipoArchivo === 'excel') {
        $x = generarExcel($solicitud, $a);
        salir(['tipo' => 'excel', 'titulo' => $x['titulo'], 'columnas' => $x['columnas'], 'datos' => $x['datos']]);
    }

    $texto = generarRespuesta($solicitud, $a);
    salir(['tipo' => 'respuesta', 'respuesta' => $texto]);

} catch (Throwable $e) {
    guardarDebug('ERROR GENERAL', $e->getMessage() . "\n" . $e->getTraceAsString());
    salir(['tipo' => 'respuesta', 'respuesta' => $e->getMessage()]);
}