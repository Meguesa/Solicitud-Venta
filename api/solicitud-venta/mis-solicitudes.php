<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

if (!portal_is_authenticated()) {
    svResponderError(401, 'SESSION_REQUIRED', 'Tu sesion del Portal ha expirado. Inicia sesion nuevamente.');
}

$usuario = portal_user();
$correo = strtolower(trim((string) ($usuario['email'] ?? '')));
if ($correo === '') {
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar el correo del vendedor.');
}

try {
    $config = svConfig();
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $solicitudes = msvObtenerSolicitudes($graphToken, $config['siteId'], $config['listId'], $correo);

    $pendientes = [];
    $aprobadas = [];
    foreach ($solicitudes as $solicitud) {
        $estatus = strtoupper(trim((string) ($solicitud['estatus'] ?? '')));
        // APROBADA queda reservada para la aprobacion final posterior al Vo.Bo. de Cobranza.
        if ($estatus === 'APROBADA') {
            $aprobadas[] = $solicitud;
        } else {
            $pendientes[] = $solicitud;
        }
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'usuario' => [
            'nombre' => (string) ($usuario['name'] ?? 'Usuario'),
            'correo' => $correo,
        ],
        'pendientes' => array_values($pendientes),
        'aprobadas' => array_values($aprobadas),
        'total' => count($solicitudes),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta mis solicitudes: ' . $error->getMessage());
    svResponderError(502, 'SELLER_REQUESTS_FAILED', 'No fue posible consultar tus solicitudes.');
}

/** @return array<int,array<string,mixed>> */
function msvObtenerSolicitudes(string $token, string $siteId, string $listId, string $correo): array
{
    $campos = implode(',', [
        'Title', 'field_1', 'field_2', 'field_8', 'field_9', 'Vendedor_Nombre', 'Vendedor_Correo',
        'field_48', 'field_63', 'Solicitud_Grupo', 'Componente_Numero', 'Componente_Total', 'Es_Principal',
        'VoBo_Estatus', 'VoBo_Fecha'
    ]);

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$select=id,lastModifiedDateTime&$expand=fields($select=' . $campos . ')&$top=200';

    $grupos = [];
    while ($url !== '') {
        $respuesta = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $correoItem = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
            if ($correoItem === '' || !hash_equals($correoItem, $correo)) continue;

            $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            if ($grupo === '') {
                $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
                $grupo = preg_replace('/-\d{2}$/', '', $title) ?: $title;
            }
            if (!preg_match('/^SV-\d{4}-\d{6,}$/', $grupo)) continue;

            $numero = max(1, (int) ($fields['Componente_Numero'] ?? 1));
            $total = max($numero, (int) ($fields['Componente_Total'] ?? 1));
            $esPrincipal = (bool) ($fields['Es_Principal'] ?? false) || $numero === 1;
            $itemIdActual = trim((string) ($item['id'] ?? ''));

            if (!isset($grupos[$grupo])) {
                $grupos[$grupo] = [
                    'folio' => $grupo,
                    'itemId' => $itemIdActual,
                    'estatus' => strtoupper(trim((string) ($fields['field_1'] ?? 'BORRADOR'))),
                    'voboEstatus' => strtoupper(trim((string) ($fields['VoBo_Estatus'] ?? ''))),
                    'voboFecha' => (string) ($fields['VoBo_Fecha'] ?? ''),
                    'fecha' => (string) ($fields['field_2'] ?? ''),
                    'cliente' => msvNombreCliente($fields),
                    'vendedor' => trim((string) ($fields['Vendedor_Nombre'] ?? '')),
                    'tipoVenta' => trim((string) ($fields['field_48'] ?? '')),
                    'precioTotal' => null,
                    'componentes' => $total,
                    'modificadoUtc' => (string) ($item['lastModifiedDateTime'] ?? ''),
                    '_principalEncontrado' => false,
                ];
            }

            $grupos[$grupo]['componentes'] = max((int) $grupos[$grupo]['componentes'], $total);
            if ((string) ($item['lastModifiedDateTime'] ?? '') > (string) $grupos[$grupo]['modificadoUtc']) {
                $grupos[$grupo]['modificadoUtc'] = (string) ($item['lastModifiedDateTime'] ?? '');
            }

            if ($esPrincipal || !$grupos[$grupo]['_principalEncontrado']) {
                if ($esPrincipal) {
                    $grupos[$grupo]['_principalEncontrado'] = true;
                    if ($itemIdActual !== '') $grupos[$grupo]['itemId'] = $itemIdActual;
                }
                $grupos[$grupo]['estatus'] = strtoupper(trim((string) ($fields['field_1'] ?? $grupos[$grupo]['estatus'])));
                $grupos[$grupo]['voboEstatus'] = strtoupper(trim((string) ($fields['VoBo_Estatus'] ?? $grupos[$grupo]['voboEstatus'])));
                $grupos[$grupo]['voboFecha'] = (string) ($fields['VoBo_Fecha'] ?? $grupos[$grupo]['voboFecha']);
                $grupos[$grupo]['fecha'] = (string) ($fields['field_2'] ?? $grupos[$grupo]['fecha']);
                $grupos[$grupo]['cliente'] = msvNombreCliente($fields) ?: $grupos[$grupo]['cliente'];
                $grupos[$grupo]['tipoVenta'] = trim((string) ($fields['field_48'] ?? $grupos[$grupo]['tipoVenta']));
                $precio = $fields['field_63'] ?? null;
                if ($precio !== null && $precio !== '') $grupos[$grupo]['precioTotal'] = (float) $precio;
            }
        }

        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }

    foreach ($grupos as &$grupo) unset($grupo['_principalEncontrado']);
    unset($grupo);

    $resultado = array_values($grupos);
    usort($resultado, static function (array $a, array $b): int {
        $fechaA = (string) ($a['modificadoUtc'] ?? $a['fecha'] ?? '');
        $fechaB = (string) ($b['modificadoUtc'] ?? $b['fecha'] ?? '');
        return strcmp($fechaB, $fechaA);
    });
    return $resultado;
}

/** @param array<string,mixed> $fields */
function msvNombreCliente(array $fields): string
{
    $nombre = trim((string) ($fields['field_8'] ?? ''));
    $apellidos = trim((string) ($fields['field_9'] ?? ''));
    return trim(preg_replace('/\s+/', ' ', $nombre . ' ' . $apellidos) ?: ($nombre . ' ' . $apellidos));
}
