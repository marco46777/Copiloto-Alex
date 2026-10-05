<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit('Solicitud no válida.');
}


/*
|--------------------------------------------------------------------------
| RECIBIR JSON
|--------------------------------------------------------------------------
*/

$entrada = file_get_contents("php://input");
$datos = json_decode($entrada, true);

if (!is_array($datos)) {
    http_response_code(400);
    exit('No se recibieron datos válidos.');
}

$titulo = trim($datos['titulo'] ?? 'COPILOTO ALEX');
$elementos = $datos['elementos'] ?? [];


/*
|--------------------------------------------------------------------------
| COMPATIBILIDAD CON EL FORMATO ANTIGUO
|--------------------------------------------------------------------------
*/

if (!is_array($elementos) || count($elementos) === 0) {

    $contenido = $datos['contenido'] ?? '';

    if ($contenido !== '') {

        $elementos = [
            [
                'tipo' => 'parrafo',
                'texto' => $contenido
            ]
        ];
    }
}


/*
|--------------------------------------------------------------------------
| FUNCIONES
|--------------------------------------------------------------------------
*/

function escapar($texto)
{
    return htmlspecialchars(
        (string)$texto,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CREAR HTML DEL DOCUMENTO
|--------------------------------------------------------------------------
*/

$html = '<!DOCTYPE html>
<html lang="es">
<head>

<meta charset="UTF-8">

<style>

@page {
    margin: 70px 50px 60px 50px;
}

body {
    font-family: DejaVu Sans, sans-serif;
    color: #252c46;
    font-size: 11px;
    line-height: 1.6;
}

.header {
    background-color: #252c46;
    color: white;
    padding: 22px;
    margin-bottom: 25px;
    border-radius: 8px;
}

.header .marca {
    font-size: 10px;
    letter-spacing: 2px;
    color: #d7c4ff;
    margin-bottom: 8px;
}

.header h1 {
    margin: 0;
    font-size: 25px;
}

.subtitulo-documento {
    margin-top: 7px;
    font-size: 10px;
    color: #d9dce8;
}

h2 {
    color: #252c46;
    font-size: 17px;
    margin-top: 22px;
    margin-bottom: 9px;
    border-left: 5px solid #a55eea;
    padding-left: 10px;
}

h3 {
    color: #a55eea;
    font-size: 14px;
    margin-top: 18px;
    margin-bottom: 7px;
}

p {
    margin-top: 5px;
    margin-bottom: 10px;
    text-align: justify;
}

ul {
    margin-top: 5px;
    margin-bottom: 15px;
}

li {
    margin-bottom: 5px;
}

.tabla-titulo {
    font-size: 13px;
    font-weight: bold;
    margin-top: 18px;
    margin-bottom: 7px;
    color: #252c46;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 5px;
    margin-bottom: 20px;
    font-size: 9px;
}

thead {
    display: table-header-group;
}

th {
    background-color: #252c46;
    color: white;
    padding: 8px 6px;
    border: 1px solid #252c46;
    text-align: left;
    font-weight: bold;
}

td {
    padding: 7px 6px;
    border: 1px solid #d4d7df;
    vertical-align: top;
}

tr:nth-child(even) td {
    background-color: #f2f0f7;
}

tr {
    page-break-inside: avoid;
}

.separador {
    height: 2px;
    background-color: #a55eea;
    margin: 18px 0;
}

.footer {
    position: fixed;
    bottom: -35px;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8px;
    color: #777;
}

.caja {
    background-color: #f5f3fa;
    border-left: 4px solid #a55eea;
    padding: 10px;
    margin: 12px 0;
}

</style>

</head>

<body>

<div class="header">

    <div class="marca">
        COPILOTO ALEX
    </div>

    <h1>' . escapar($titulo) . '</h1>

    <div class="subtitulo-documento">
        Documento generado automáticamente
    </div>

</div>
';


/*
|--------------------------------------------------------------------------
| GENERAR ELEMENTOS
|--------------------------------------------------------------------------
*/

foreach ($elementos as $elemento) {

    if (!is_array($elemento)) {
        continue;
    }

    $tipo = strtolower(
        trim($elemento['tipo'] ?? '')
    );


    /*
    |--------------------------------------------------------------------------
    | TÍTULO
    |--------------------------------------------------------------------------
    */

    if ($tipo === 'titulo') {

        $texto = $elemento['texto'] ?? '';

        if ($texto !== '') {

            $html .= '
                <h2>' .
                escapar($texto) .
                '</h2>
            ';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SUBTÍTULO
    |--------------------------------------------------------------------------
    */

    elseif ($tipo === 'subtitulo') {

        $texto = $elemento['texto'] ?? '';

        if ($texto !== '') {

            $html .= '
                <h3>' .
                escapar($texto) .
                '</h3>
            ';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | PÁRRAFO
    |--------------------------------------------------------------------------
    */

    elseif ($tipo === 'parrafo') {

        $texto = trim(
            $elemento['texto'] ?? ''
        );

        if ($texto !== '') {

            $html .= '
                <p>' .
                nl2br(escapar($texto)) .
                '</p>
            ';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | LISTA
    |--------------------------------------------------------------------------
    */

    elseif ($tipo === 'lista') {

        $items = $elemento['items'] ?? [];

        if (is_array($items) && count($items) > 0) {

            $html .= '<ul>';

            foreach ($items as $item) {

                $html .= '
                    <li>' .
                    escapar($item) .
                    '</li>
                ';
            }

            $html .= '</ul>';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | TABLA
    |--------------------------------------------------------------------------
    */

    elseif ($tipo === 'tabla') {

        $columnas = $elemento['columnas'] ?? [];
        $filas = $elemento['filas'] ?? [];

        if (
            !is_array($columnas) ||
            count($columnas) === 0
        ) {
            continue;
        }

        $html .= '<div class="tabla-titulo">';

        if (!empty($elemento['titulo'])) {

            $html .= escapar(
                $elemento['titulo']
            );
        }

        $html .= '</div>';

        $html .= '<table>';

        /*
        ENCABEZADOS
        */

        $html .= '<thead><tr>';

        foreach ($columnas as $columna) {

            $html .= '
                <th>' .
                escapar($columna) .
                '</th>
            ';
        }

        $html .= '</tr></thead>';

        /*
        FILAS
        */

        $html .= '<tbody>';

        if (is_array($filas)) {

            foreach ($filas as $fila) {

                if (!is_array($fila)) {
                    continue;
                }

                $html .= '<tr>';

                foreach ($columnas as $indice => $columna) {

                    $valor = $fila[$indice] ?? '';

                    $html .= '
                        <td>' .
                        nl2br(
                            escapar($valor)
                        ) .
                        '</td>
                    ';
                }

                $html .= '</tr>';
            }
        }

        $html .= '</tbody>';

        $html .= '</table>';
    }


    /*
    |--------------------------------------------------------------------------
    | SEPARADOR
    |--------------------------------------------------------------------------
    */

    elseif ($tipo === 'separador') {

        $html .= '
            <div class="separador"></div>
        ';
    }
}


/*
|--------------------------------------------------------------------------
| PIE DE PÁGINA
|--------------------------------------------------------------------------
*/

$html .= '

<div class="footer">
    COPILOTO ALEX · Documento generado automáticamente
</div>

</body>
</html>
';


/*
|--------------------------------------------------------------------------
| CONFIGURAR DOMPDF
|--------------------------------------------------------------------------
*/

$options = new Options();

$options->set(
    'isHtml5ParserEnabled',
    true
);

$options->set(
    'isRemoteEnabled',
    false
);

$options->set(
    'defaultFont',
    'DejaVu Sans'
);


/*
|--------------------------------------------------------------------------
| CREAR PDF
|--------------------------------------------------------------------------
*/

$dompdf = new Dompdf($options);

$dompdf->loadHtml(
    $html,
    'UTF-8'
);

$dompdf->setPaper(
    'A4',
    'portrait'
);

$dompdf->render();


/*
|--------------------------------------------------------------------------
| NOMBRE DEL ARCHIVO
|--------------------------------------------------------------------------
*/

$nombre = preg_replace(
    '/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ_-]/u',
    '_',
    $titulo
);

$nombre = trim(
    $nombre,
    '_'
);

if ($nombre === '') {
    $nombre = 'COPILOTO_ALEX';
}

$nombre .= '.pdf';


/*
|--------------------------------------------------------------------------
| DESCARGAR
|--------------------------------------------------------------------------
*/

$dompdf->stream(
    $nombre,
    [
        'Attachment' => true
    ]
);

exit;