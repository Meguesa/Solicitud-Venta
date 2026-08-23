<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$config = svConfig();
$claims = svUsuarioAutenticado($config['tenantId'], $config['clientId']);
$correoUsuario = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
if ($correoUsuario === '') svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar el correo del usuario.');

$rawBody = file_get_contents('php://input');
$payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
if (!is_array($payload)) svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio de la solicitud no es valido.');
}

$componentes = $payload['componentes'] ?? null;
$registros = $payload['registros'] ?? null;
if (!is_array($componentes) || !is_array($registros) || count($componentes) !== count($registros) || count($componentes) < 1) {
    svResponderError(400, 'INVALID_COMPONENTS', 'Los componentes y registros no coinciden.');
}

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $resultado = [];

    foreach (array_values($componentes) as $index => $componente) {
        if (!is_array($componente) || !is_array($registros[$index] ?? null)) {
            throw new RuntimeException('Uno de los componentes no tiene un formato valido.');
        }

        $numeroComponente = $index + 1;
        $registro = $registros[$index];
        $itemId = trim((string) ($registro['itemId'] ?? ''));
        if ($itemId === '' || !ctype_digit($itemId)) {
            throw new RuntimeException('El componente ' . $numeroComponente . ' no tiene un itemId valido.');
        }

        $item = obtenerItemServicio($graphToken, $config['siteId'], $config['listId'], $itemId);
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        verificarItemServicio($fields, $folio, $correoUsuario, $numeroComponente);

        $tipo = strtoupper(trim((string) ($componente['tipoSolicitud'] ?? '')));
        $clave = '';
        $numeroServicio = '';

        if ($tipo === 'SERVICIO') {
            $servicioTipo = strtoupper(trim((string) ($componente['servicioTipo'] ?? '')));
            $servicioAtaud = strtoupper(trim((string) ($componente['servicioAtaud'] ?? '')));
            $tipoOperacion = strtoupper(trim((string) ($componente['tipoOperacion'] ?? '')));
            $numeroServicio = strtoupper(trim((string) ($componente['servicioNumero'] ?? '')));

            if ($numeroServicio === '') {
                throw new RuntimeException('Falta el numero del servicio en el componente ' . $numeroComponente . '.');
            }

            $clave = construirClaveServicio($servicioTipo, $servicioAtaud, $numeroServicio, $tipoOperacion);
            if ($clave === '') {
                throw new RuntimeException('No fue posible construir la clave del servicio en el componente ' . $numeroComponente . '.');
            }
        } elseif ($tipo === 'LOTE' || $tipo === 'NICHO') {
            $clave = strtoupper(trim((string) ($componente['propiedadClave'] ?? '')));
            if ($clave === '') {
                throw new RuntimeException('Falta la clave de propiedad en el componente ' . $numeroComponente . '.');
            }
        } else {
            throw new RuntimeException('El tipo del componente ' . $numeroComponente . ' no es valido.');
        }

        actualizarReferenciaComponente($graphToken, $config['siteId'], $config['listId'], $itemId, $clave);
        $resultado[] = [
            'itemId' => $itemId,
            'componenteNumero' => $numeroComponente,
            'tipo' => $tipo,
            'numeroServicio' => $numeroServicio,
            'claveComponente' => $clave,
            'claveServicio' => $tipo === 'SERVICIO' ? $clave : null,
        ];
    }
} catch (Throwable $error) {
    error_log('Solicitud Venta referencias componentes: ' . $error->getMessage());
    svResponderError(502, 'COMPONENT_REFERENCE_SYNC_FAILED', 'No fue posible guardar las referencias de los componentes: ' . $error->getMessage());
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'folio' => $folio,
    'componentes' => $resultado,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function construirClaveServicio(string $servicioTipo, string $ataud, string $numero, string $operacion): string
{
    $servicios = [
        'VELACION E INHUMACION' => 'VI',
        'VELACION Y CREMACION' => 'VC',
        'CREMACION DIRECTA' => 'CD',
        'INHUMACION DIRECTA' => 'ID',
        'RENTA DE CAPILLA' => 'RC',
        'TRASLADO' => 'TR',
        'OTRO' => 'OT',
    ];

    $ataudes = [
        'ATAUD MADERA BASICO' => 'ATMADBA',
        'ATAUD MADERA EXCLUSIVO' => 'ATMADEX',
        'ATAUD MADERA DE LUJO' => 'ATMADLU',
        'ATAUD METALICO BASICO' => 'ATMETBA',
        'ATAUD METALICO EXCLUSIVO' => 'ATMETEX',
        'OTRO' => 'ATOTRO',
    ];

    $cremacionDirecta = [
        'PREVISION' => 'OPATMET',
        'USO INMEDIATO' => 'URNABAS',
    ];

    $servicioTipo = strtoupper(trim($servicioTipo));
    $ataud = strtoupper(trim($ataud));
    $numero = strtoupper(trim($numero));
    $operacion = strtoupper(trim($operacion));

    if ($numero === '') return '';

    $codigoServicio = $servicios[$servicioTipo] ?? '';
    if ($codigoServicio === '') return '';

    if ($servicioTipo === 'CREMACION DIRECTA') {
        $codigoOperacion = $cremacionDirecta[$operacion] ?? '';
        if ($codigoOperacion === '') return '';
        return $codigoServicio . '-' . $codigoOperacion . '-' . $numero;
    }

    $codigoAtaud = $ataudes[$ataud] ?? '';
    if ($codigoAtaud === '') return '';

    return $codigoServicio . '-' . $codigoAtaud . '-' . $numero;
}

/** @return array<string,mixed> */
function obtenerItemServicio(string $token, string $siteId, string $listId, string $itemId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Tipo_Componente)';

    return svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}

function verificarItemServicio(array $fields, string $folio, string $correoUsuario, int $numeroComponente): void
{
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    $numero = (int) ($fields['Componente_Numero'] ?? 0);

    if ($estado !== 'BORRADOR') throw new RuntimeException('El componente ' . $numeroComponente . ' ya no esta en BORRADOR.');
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) throw new RuntimeException('El componente ' . $numeroComponente . ' no pertenece al usuario autenticado.');
    if ($grupo !== $folio) throw new RuntimeException('El componente ' . $numeroComponente . ' no pertenece a la solicitud indicada.');
    if ($numero !== $numeroComponente) throw new RuntimeException('La numeracion del componente ' . $numeroComponente . ' no coincide con SharePoint.');
}

function actualizarReferenciaComponente(string $token, string $siteId, string $listId, string $itemId, string $clave): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '/fields';

    $body = json_encode(['field_4' => $clave], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la referencia del componente.');

    svCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);
}
