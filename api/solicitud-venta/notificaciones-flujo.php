<?php

declare(strict_types=1);

require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/sharepoint-grupos.php';

/**
 * Envia la notificacion cuando una Solicitud de Venta entra a PENDIENTE VOBO.
 *
 * La transicion de estatus debe haberse completado ANTES de llamar esta funcion.
 * Cualquier excepcion debe ser atrapada por el endpoint llamador para no revertir
 * una solicitud valida solo por un problema de correo.
 *
 * @param array<string,string> $backendConfig
 * @param array<string,mixed> $principalFields
 * @return array{enviado:bool,destinatarios:array<int,string>,grupo:string}
 */
function svNotificarEntradaVoboComercial(
    string $graphToken,
    array $backendConfig,
    string $folio,
    array $principalFields,
    int $componentes
): array {
    $grupo = 'Solicitud Venta - Notificaciones Comercial';

    $groupConfig = svSharePointGruposConfig();
    $siteWebUrl = svSharePointSiteWebUrlDesdeGraph($graphToken, $groupConfig['siteId']);
    $host = strtolower(trim((string) parse_url($siteWebUrl, PHP_URL_HOST)));
    if ($host === '') {
        throw new RuntimeException('No fue posible determinar el host del sitio de SharePoint para notificaciones.');
    }

    $sharePointToken = svSharePointTokenConCertificado(
        $groupConfig['tenantId'],
        $groupConfig['clientId'],
        $host,
        $groupConfig['pfxPath'],
        $groupConfig['pfxPassword']
    );

    $destinatarios = svSharePointCorreosGrupo(
        $sharePointToken,
        $siteWebUrl,
        $grupo
    );
    $destinatarios = svNormalizarDestinatarios($destinatarios);
    if (!$destinatarios) {
        throw new RuntimeException('El grupo ' . $grupo . ' no contiene correos validos.');
    }

    $mailConfig = svNotificacionesConfig();
    $cliente = svNotificacionNombreCliente($principalFields);
    $vendedor = trim((string) ($principalFields['Vendedor_Nombre'] ?? ''));
    $vendedorCorreo = strtolower(trim((string) ($principalFields['Vendedor_Correo'] ?? '')));
    if ($vendedor === '') $vendedor = $vendedorCorreo !== '' ? $vendedorCorreo : 'No disponible';

    $tipoVenta = trim((string) ($principalFields['field_48'] ?? ''));
    if ($tipoVenta === '') $tipoVenta = 'No disponible';

    $precio = $principalFields['field_63'] ?? null;
    $precioTexto = 'No disponible';
    if ($precio !== null && $precio !== '' && is_numeric($precio)) {
        $precioTexto = '$' . number_format((float) $precio, 2, '.', ',');
    }

    $fecha = trim((string) ($principalFields['field_2'] ?? ''));
    if ($fecha === '') $fecha = gmdate('c');

    $reviewUrl = 'https://portal.juanpablo.com.mx/solicitud-venta/vobo/?etapa=comercial';
    $asunto = 'Nueva Solicitud de Venta pendiente de Vo.Bo. | ' . $folio;

    $html = svNotificacionPlantillaComercial([
        'folio' => $folio,
        'cliente' => $cliente,
        'vendedor' => $vendedor,
        'vendedorCorreo' => $vendedorCorreo,
        'tipoVenta' => $tipoVenta,
        'precio' => $precioTexto,
        'componentes' => (string) max(1, $componentes),
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

    // Evidencia administrativa de que el envio fue aceptado por Microsoft Graph.
    // No se usa para controlar el flujo: el propio estatus impide repetir la
    // transicion BORRADOR/PENDIENTE FIRMA -> PENDIENTE VOBO.
    try {
        svGuardarEvidenciaNotificacion(
            $graphToken,
            $backendConfig['siteId'],
            $folio,
            '_NOTIFICACION_COMERCIAL.json',
            [
                'version' => 1,
                'folio' => $folio,
                'etapa' => 'VOBO_COMERCIAL',
                'grupo' => $grupo,
                'destinatarios' => $destinatarios,
                'remitente' => $mailConfig['sender'],
                'enviadoUtc' => gmdate('c'),
                'asunto' => $asunto,
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Solicitud Venta evidencia notificacion Comercial: ' . $auditError->getMessage());
    }

    return [
        'enviado' => true,
        'destinatarios' => $destinatarios,
        'grupo' => $grupo,
    ];
}

/** @param array<string,mixed> $fields */
function svNotificacionNombreCliente(array $fields): string
{
    $nombre = trim((string) ($fields['field_8'] ?? ''));
    $apellidos = trim((string) ($fields['field_9'] ?? ''));
    $completo = trim(preg_replace('/\s+/', ' ', $nombre . ' ' . $apellidos) ?: ($nombre . ' ' . $apellidos));
    return $completo !== '' ? $completo : 'Cliente no disponible';
}

/** @param array<string,string> $datos */
function svNotificacionPlantillaComercial(array $datos): string
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
        . '<h1 style="font-size:24px;margin:8px 0 6px">Nueva Solicitud de Venta</h1>'
        . '<p style="margin:0;color:#665d58;line-height:1.5">Hay una nueva solicitud pendiente de revisión y Vo.Bo. Comercial.</p>'
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
        . '<div style="padding-top:24px"><a href="' . $reviewUrl . '" style="display:inline-block;background:#225b8a;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 20px;border-radius:8px">Revisar en Vo.Bo. Comercial</a></div>'
        . '<p style="margin:22px 0 0;color:#756a64;font-size:12px;line-height:1.5">Este mensaje fue generado automáticamente por Solicitud de Venta. No es necesario responder a este correo.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function svObtenerDriveExpedientesNotificaciones(string $graphToken, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name';
    $response = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
    ]);

    foreach (($response['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) {
            $id = trim((string) ($drive['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas para registrar la notificacion.');
}

/** @param array<string,mixed> $data */
function svGuardarEvidenciaNotificacion(
    string $graphToken,
    string $siteId,
    string $folio,
    string $archivo,
    array $data
): void {
    $driveId = svObtenerDriveExpedientesNotificaciones($graphToken, $siteId);
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la evidencia de notificacion.');

    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    svCurlJson($url, 'PUT', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8',
    ], $body);
}
