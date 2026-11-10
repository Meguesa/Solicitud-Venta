<?php
declare(strict_types=1);
// Diagnostico de esquema de la lista QA. SOLO LECTURA, sin datos de clientes.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
require_once rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')
    . '/api/solicitud-venta-qa/_common.php';

function qaEsquemaResponder(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    qaEsquemaResponder(405, ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}
try {
    $cfg = svConfig();
    svUsuarioAutenticado($cfg['tenantId'], $cfg['clientId']);
    $token = svGraphToken($cfg['tenantId'], $cfg['clientId'], $cfg['clientSecret']);
    $base = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($cfg['siteId'])
        . '/lists/' . rawurlencode($cfg['listId']);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $list = svCurlJson($base . '?$select=id,name', 'GET', $headers);
    if (($list['id'] ?? '') !== $cfg['listId']
        || ($list['name'] ?? '') !== 'Solicitudes_Venta') {
        throw new RuntimeException('QA_LIST_MISMATCH');
    }
    $url = $base . '/columns?$select=name,displayName&$top=200';
    $columns = [];
    for ($page = 0; $url !== '' && $page < 10; $page++) {
        $response = svCurlJson($url, 'GET', $headers);
        foreach (($response['value'] ?? []) as $col) {
            if (is_array($col) && is_string($col['name'] ?? null)) {
                $columns[$col['name']] = true;
            }
        }
        $next = (string) ($response['@odata.nextLink'] ?? '');
        if ($next !== '' && !str_starts_with($next, $base . '/columns?')) {
            throw new RuntimeException('QA_UNEXPECTED_PAGINATION');
        }
        $url = $next;
    }
    if ($url !== '') throw new RuntimeException('QA_COLUMN_PAGINATION_INCOMPLETE');

    // Columnas necesarias para crear el primer borrador y controlar el flujo.
    $core = [
        'Title', 'field_1', 'field_2', 'Tipo_Solicitud', 'field_47', 'field_48',
        'Vendedor_Nombre', 'Vendedor_Correo', 'Componente_Numero', 'Componente_Total',
        'Tipo_Componente', 'Es_Principal', 'Solicitud_Grupo',
        'field_102', 'field_103', 'field_104'
    ];
    $extended = [];
    for ($i = 1; $i <= 82; $i++) $extended[] = 'field_' . $i;
    for ($i = 88; $i <= 104; $i++) $extended[] = 'field_' . $i;
    $named = [
        'Cliente_Tipo_ID', 'Cliente_Apellido_Paterno', 'Cliente_Apellido_Materno',
        'Cliente_Ciudad', 'Cliente_Domicilio_Anterior',
        'Cliente_Antiguedad_Domicilio_Anterior', 'Cliente_Escolaridad',
        'Cliente_Nacionalidad', 'Cliente_Regimen_Matrimonial', 'Cliente_Telefono',
        'Observaciones_Solicitud', 'Documento_ID_Titular',
        'Precio_Base_Componente', 'Monto_Componente',
        'Cobranza_Informacion_Validada', 'Cobranza_Pago_Inicial_Validado',
        'Cobranza_Estatus', 'Cobranza_Fecha', 'Cobranza_Por',
        'Cobranza_Motivo_Correccion', 'VoBo_Observaciones'
    ];
    $missing = static function (array $expected) use ($columns): array {
        return array_values(array_filter(
            $expected, static fn(string $name): bool => !isset($columns[$name])
        ));
    };
    $faltantesBase = $missing($core);
    $faltantesExtendidas = $missing($extended);
    $faltantesNombres = $missing($named);
    qaEsquemaResponder(200, [
        'ok' => count($faltantesBase) === 0,
        'entorno' => 'QA',
        'sitio' => 'SolicitudVentaQA',
        'lista' => 'Solicitudes_Venta',
        'modo' => 'SOLO_LECTURA',
        'total_columnas' => count($columns),
        'obligatorias_presentes' => count($core) - count($faltantesBase),
        'obligatorias_esperadas' => count($core),
        'faltantes_obligatorias' => $faltantesBase,
        'extendidas_presentes' => count($extended) - count($faltantesExtendidas),
        'extendidas_esperadas' => count($extended),
        'faltantes_extendidas' => $faltantesExtendidas,
        'faltantes_nombres_funcionales' => $faltantesNombres,
        'listo_para_pruebas_integrales' => !$faltantesBase
            && !$faltantesExtendidas && !$faltantesNombres,
    ]);
} catch (Throwable $e) {
    error_log('Solicitud Venta QA diagnostico esquema: ' . $e->getMessage());
    qaEsquemaResponder(503, [
        'ok' => false, 'entorno' => 'QA', 'error' => 'QA_SCHEMA_CHECK_FAILED'
    ]);
}
