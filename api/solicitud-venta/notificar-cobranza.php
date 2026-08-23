<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/sharepoint-grupos.php';
require_once __DIR__ . '/notificaciones-flujo.php';

function ncbError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ncbError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}
if (!portal_is_authenticated()) {
    ncbError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');
}
if (!portal_user_can_vobo()) {
    ncbError(403, 'VOBO_FORBIDDEN', 'Tu cuenta no tiene autorizacion para notificar una aprobacion Comercial.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) $payload = [];

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    ncbError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');
}

try {
    $config = svConfig();
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = ncbObtenerPrincipal($graphToken, $config['siteId'], $config['listId'], $folio);

    $estatus = strtoupper(trim((string) ($principal['field_1'] ?? '')));
    if ($estatus !== 'PENDIENTE COBRANZA') {
        ncbError(409, 'INVALID_STATUS', 'La solicitud debe estar en PENDIENTE COBRANZA antes de enviar esta notificacion.');
    }

    [$grupo, $destinatarios] = ncbDestinatariosCobranza($graphToken);
    $mailConfig = svNotificacionesConfig();

    $cliente = svNotificacionNombreCliente($principal);
    $vendedor = trim((string) ($principal['Vendedor_Nombre'] ?? ''));
    $vendedorCorreo = strtolower(trim((string) ($principal['Vendedor_Correo'] ?? '')));
    if ($vendedor === '') $vendedor = $vendedorCorreo !== '' ? $vendedorCorreo : 'No disponible';

    $tipoVenta = trim((string) ($principal['field_48'] ?? ''));
    if ($tipoVenta === '') $tipoVenta = 'No disponible';

    $precio = $principal['field_63'] ?? null;
    $precioTexto = ($precio !== null && $precio !== '' && is_numeric($precio))
        ? '$' . number_format((float) $precio, 2, '.', ',')
        : 'No disponible';

    $componentes = max(1, (int) ($principal['Componente_Total'] ?? 1));
    $fecha = trim((string) ($principal['field_2'] ?? ''));
    if ($fecha === '') $fecha = gmdate('c');

    $reviewUrl = 'https://portal.juanpablo.com.mx/solicitud-venta/vobo/?etapa=cobranza';
    $asunto = 'Solicitud en espera de autorizacion de Cobranza | ' . $folio;
    $html = ncbPlantillaCobranza([
        'folio' => $folio,
        'cliente' => $cliente,
        'vendedor' => $vendedor,
        'vendedorCorreo' => $vendedorCorreo,
        'tipoVenta' => $tipoVenta,
        'precio' => $precioTexto,
        'componentes' => (string) $componentes,
        'fecha' => $fecha,
        'reviewUrl' => $reviewUrl,
    ]);

    svEnviarCorreoGraph(
        $graphToken,
        $mailConfig['sender'],
        $destinatarios,
        $asunto,
        $html
    );

    try {
        svGuardarEvidenciaNotificacion(
            $graphToken,
            $config['siteId'],
            $folio,
            '_NOTIFICACION_COBRANZA.json',
            [
                'version' => 1,
                'folio' => $folio,
                'etapa' => 'VOBO_COBRANZA',
                'grupo' => $grupo,
                'destinatarios' => $destinatarios,
                'remitente' => $mailConfig['sender'],
                'enviadoUtc' => gmdate('c'),
                'asunto' => $asunto,
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Solicitud Venta evidencia notificacion Cobranza: ' . $auditError->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'grupo' => $grupo,
        'destinatarios' => $destinatarios,
        'message' => 'Notificacion de Cobranza enviada correctamente.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta notificacion Cobranza ' . $folio . ': ' . $error->getMessage());
    ncbError(502, 'COBRANZA_NOTIFICATION_FAILED', 'La solicitud avanzo a Cobranza, pero no fue posible enviar la notificacion automatica.');
}

/** @return array<string,mixed> */
function ncbObtenerPrincipal(string $token, string $siteId, string $listId, string $folio): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields&$top=200';
    $paginas = 0;
    $candidato = null;

    while ($url !== '' && $paginas < 50) {
        $data = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
            if ($grupo !== $folio && $title !== $folio) continue;

            if ($candidato === null) $candidato = $fields;
            $numero = (int) ($fields['Componente_Numero'] ?? 0);
            $principal = filter_var($fields['Es_Principal'] ?? false, FILTER_VALIDATE_BOOLEAN) || $numero === 1;
            if ($principal) return $fields;
        }

        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        $paginas++;
    }

    if (is_array($candidato)) return $candidato;
    throw new RuntimeException('No se encontro la solicitud indicada en SharePoint.');
}

/** @return array{0:string,1:array<int,string>} */
function ncbDestinatariosCobranza(string $graphToken): array
{
    $groupConfig = svSharePointGruposConfig();
    $siteWebUrl = svSharePointSiteWebUrlDesdeGraph($graphToken, $groupConfig['siteId']);
    $host = strtolower(trim((string) parse_url($siteWebUrl, PHP_URL_HOST)));
    if ($host === '') {
        throw new RuntimeException('No fue posible determinar el host del sitio de SharePoint.');
    }

    $sharePointToken = svSharePointTokenConCertificado(
        $groupConfig['tenantId'],
        $groupConfig['clientId'],
        $host,
        $groupConfig['pfxPath'],
        $groupConfig['pfxPassword']
    );

    // Se prioriza un grupo dedicado de notificaciones. Si aun no existe,
    // se reutiliza el grupo operativo de Cobranza que ya controla el acceso.
    $candidatos = [
        'Solicitud Venta - Notificaciones Cobranza',
        'Notificaciones Solicitud de Venta - Cobranza',
        'Solicitud Venta - Cobranza',
    ];

    $ultimoError = null;
    foreach ($candidatos as $grupo) {
        try {
            $destinatarios = svNormalizarDestinatarios(
                svSharePointCorreosGrupo($sharePointToken, $siteWebUrl, $grupo)
            );
            if ($destinatarios) return [$grupo, $destinatarios];
        } catch (Throwable $error) {
            $ultimoError = $error;
        }
    }

    throw new RuntimeException(
        'No se encontro un grupo de Cobranza con correos validos.'
        . ($ultimoError ? ' ' . $ultimoError->getMessage() : '')
    );
}

/** @param array<string,string> $datos */
function ncbPlantillaCobranza(array $datos): string
{
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $folio = $h($datos['folio'] ?? '');
    $cliente = $h($datos['cliente'] ?? '');
    $vendedor = $h($datos['vendedor'] ?? '');
    $vendedorCorreo = $h($datos['vendedorCorreo'] ?? '');
    $tipoVenta = $h($datos['tipoVenta'] ?? '');
    $precio = $h($datos['precio'] ?? '');
    $componentes = $h($datos['componentes'] ?? '');
    $fecha = $h($datos['fecha'] ?? '');
    $reviewUrl = $h($datos['reviewUrl'] ?? '');

    $vendedorDetalle = $vendedorCorreo !== '' && strcasecmp($vendedor, $vendedorCorreo) !== 0
        ? $vendedor . '<br><span style="color:#6b625d;font-size:13px">' . $vendedorCorreo . '</span>'
        : $vendedor;

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f5f1ec;font-family:Arial,sans-serif;color:#2b1b15">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f1ec;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e4d9cf;border-radius:14px;overflow:hidden">'
        . '<tr><td style="padding:24px 28px;border-top:5px solid #e28a16">'
        . '<div style="font-size:12px;letter-spacing:1.5px;font-weight:700;color:#225b8a">JARDINES DE JUAN PABLO</div>'
        . '<h1 style="font-size:24px;margin:8px 0 6px">Solicitud en espera de Cobranza</h1>'
        . '<p style="margin:0;color:#665d58;line-height:1.5">Vo.Bo. Comercial autorizó la solicitud. Se encuentra pendiente de revisión y autorización de Cobranza.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px 22px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;font-size:14px">'
        . '<tr><td style="width:38%;font-weight:700;border-bottom:1px solid #eee7e1">Folio</td><td style="border-bottom:1px solid #eee7e1">' . $folio . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Cliente</td><td style="border-bottom:1px solid #eee7e1">' . $cliente . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Vendedor</td><td style="border-bottom:1px solid #eee7e1">' . $vendedorDetalle . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Tipo de venta</td><td style="border-bottom:1px solid #eee7e1">' . $tipoVenta . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Precio total</td><td style="border-bottom:1px solid #eee7e1">' . $precio . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Componentes</td><td style="border-bottom:1px solid #eee7e1">' . $componentes . '</td></tr>'
        . '<tr><td style="font-weight:700">Fecha de solicitud</td><td>' . $fecha . '</td></tr>'
        . '</table>'
        . '<div style="padding-top:24px"><a href="' . $reviewUrl . '" style="display:inline-block;background:#225b8a;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 20px;border-radius:8px">Revisar en Vo.Bo. Cobranza</a></div>'
        . '<p style="margin:22px 0 0;color:#756a64;font-size:12px;line-height:1.5">Este mensaje fue generado automáticamente por Solicitud de Venta. No es necesario responder a este correo.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}
