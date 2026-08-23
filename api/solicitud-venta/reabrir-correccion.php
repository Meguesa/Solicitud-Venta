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
if ($correoUsuario === '') {
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar al vendedor.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');
}

$itemId = trim((string) ($payload['itemId'] ?? ''));
if ($itemId !== '' && (!ctype_digit($itemId) || (int) $itemId <= 0)) {
    svResponderError(400, 'INVALID_ITEM_ID', 'El identificador de la solicitud no es valido.');
}

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = rcEncontrarPrincipal(
        $graphToken,
        $config['siteId'],
        $config['listId'],
        $folio,
        $itemId,
        $correoUsuario
    );

    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    $principalId = trim((string) ($principal['id'] ?? ''));
    $estatus = strtoupper(trim((string) ($principalFields['field_1'] ?? '')));
    $voboEstatus = strtoupper(trim((string) ($principalFields['VoBo_Estatus'] ?? '')));
    $firmaCliente = strtoupper(trim((string) ($principalFields['field_102'] ?? '')));
    $firmaVendedor = strtoupper(trim((string) ($principalFields['field_103'] ?? '')));

    // En correcciones, una liga remota generada por error pudo sobrescribir el
    // indicador field_102 aunque la firma original siga existiendo en el expediente.
    // La evidencia/archivo del expediente es la fuente durable para recuperar la
    // firma ya realizada sin obligar al cliente a firmar otra vez.
    $firmaClienteExpediente = false;
    $firmaVendedorExpediente = false;
    try {
        $driveId = rcObtenerDriveExpedientes($graphToken, $config['siteId']);
        $firmaClienteExpediente = rcExisteArchivoExpediente($graphToken, $driveId, $folio, '_EVIDENCIA_FIRMA_CLIENTE.json')
            || rcExisteArchivoExpediente($graphToken, $driveId, $folio, 'FIRMA_CLIENTE.png');
        $firmaVendedorExpediente = rcExisteArchivoExpediente($graphToken, $driveId, $folio, 'FIRMA_VENDEDOR.png');
    } catch (Throwable $firmaError) {
        error_log('Solicitud Venta verificar firmas expediente ' . $folio . ': ' . $firmaError->getMessage());
    }

    $firmaClienteConfirmada = $firmaCliente === 'FIRMADO' || $firmaClienteExpediente;
    $firmaVendedorConfirmada = $firmaVendedor === 'FIRMADO' || $firmaVendedorExpediente;

    if ($principalId !== '') {
        $restaurarFirmas = [];
        if ($firmaClienteConfirmada && $firmaCliente !== 'FIRMADO') $restaurarFirmas['field_102'] = 'FIRMADO';
        if ($firmaVendedorConfirmada && $firmaVendedor !== 'FIRMADO') $restaurarFirmas['field_103'] = 'FIRMADO';
        if ($restaurarFirmas) {
            rcActualizarCampos($graphToken, $config['siteId'], $config['listId'], $principalId, $restaurarFirmas);
            if (isset($restaurarFirmas['field_102'])) $firmaCliente = 'FIRMADO';
            if (isset($restaurarFirmas['field_103'])) $firmaVendedor = 'FIRMADO';
        }
    }

    $recuperacionFirmaDuplicada = $estatus === 'PENDIENTE FIRMA'
        && $voboEstatus === 'CORRECCION'
        && $firmaClienteConfirmada
        && $firmaVendedorConfirmada;

    if (!in_array($estatus, ['CORRECCION', 'BORRADOR'], true) && !$recuperacionFirmaDuplicada) {
        svResponderError(409, 'CORRECTION_NOT_EDITABLE', 'La solicitud ya no se encuentra disponible para correccion.');
    }
    if ($estatus === 'BORRADOR' && $voboEstatus !== 'CORRECCION') {
        svResponderError(409, 'CORRECTION_NOT_REQUESTED', 'La solicitud no tiene una correccion pendiente de atender.');
    }

    $motivo = rcMotivo($principalFields);
    $lugar = rcTextoPlano((string) ($principalFields['field_3'] ?? ''));
    $grupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($grupo === '') $grupo = $folio;

    $items = rcObtenerGrupo($graphToken, $config['siteId'], $config['listId'], $grupo, $folio, $principal);
    if (!$items) {
        svResponderError(409, 'GROUP_EMPTY', 'No se encontraron componentes de la solicitud.');
    }

    // Para una correccion, SharePoint es la fuente de verdad de los componentes.
    // El JSON de reanudacion pudo haberse sobrescrito durante intentos previos de
    // firma/correccion, por lo que reconstruimos los datos antes de cambiar estados.
    $componentesDetalle = rcConstruirComponentesDetalle($items);
    $distribucionTipo = strtoupper(trim((string) ($principalFields['Distribucion_Tipo'] ?? 'AUTOMATICA')));
    if (!in_array($distribucionTipo, ['AUTOMATICA', 'MANUAL_PROMOCION'], true)) $distribucionTipo = 'AUTOMATICA';
    $promocionNombre = trim((string) ($principalFields['Promocion_Nombre'] ?? ''));

    $actualizados = 0;
    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
        if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
            svResponderError(403, 'CORRECTION_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
        }

        $estadoItem = strtoupper(trim((string) ($fields['field_1'] ?? '')));
        $estadoRecuperable = in_array($estadoItem, ['CORRECCION', 'BORRADOR'], true)
            || ($estadoItem === 'PENDIENTE FIRMA' && $recuperacionFirmaDuplicada);
        if (!$estadoRecuperable) {
            svResponderError(409, 'GROUP_STATUS_MISMATCH', 'Uno de los componentes ya no esta disponible para correccion.');
        }

        if ($estadoItem === 'CORRECCION' || ($estadoItem === 'PENDIENTE FIRMA' && $recuperacionFirmaDuplicada)) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') throw new RuntimeException('Componente sin ID de SharePoint.');
            rcActualizarCampos($graphToken, $config['siteId'], $config['listId'], $id, ['field_1' => 'BORRADOR']);
            $actualizados++;
        }
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'itemId' => $principalId !== '' ? $principalId : $itemId,
        'estatus' => 'BORRADOR',
        'voboEstatus' => 'CORRECCION',
        'motivo' => $motivo,
        'lugar' => $lugar,
        'firmaCliente' => $firmaClienteConfirmada ? 'FIRMADO' : $firmaCliente,
        'firmaVendedor' => $firmaVendedorConfirmada ? 'FIRMADO' : $firmaVendedor,
        'firmaClienteExpediente' => $firmaClienteExpediente,
        'firmaVendedorExpediente' => $firmaVendedorExpediente,
        'componentes' => count($items),
        'componentesDetalle' => $componentesDetalle,
        'distribucionTipo' => $distribucionTipo,
        'promocionNombre' => $promocionNombre,
        'componentesReabiertos' => $actualizados,
        'recuperadoDesdePendienteFirma' => $recuperacionFirmaDuplicada,
        'message' => $recuperacionFirmaDuplicada
            ? 'La correccion fue recuperada usando las firmas ya existentes en el expediente.'
            : 'La solicitud quedo habilitada para atender la correccion solicitada.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta reabrir correccion ' . $folio . ': ' . $error->getMessage());
    svResponderError(502, 'CORRECTION_REOPEN_FAILED', 'No fue posible habilitar la solicitud para correccion.');
}

/** @return array<string,mixed> */
function rcEncontrarPrincipal(
    string $token,
    string $siteId,
    string $listId,
    string $folio,
    string $itemId,
    string $correoUsuario
): array {
    if ($itemId !== '') {
        try {
            $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
                . '/lists/' . rawurlencode($listId)
                . '/items/' . rawurlencode($itemId)
                . '?$select=id&$expand=fields';
            $item = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (rcCoincide($fields, $folio, $correoUsuario)) return $item;
        } catch (Throwable $error) {
            error_log('Solicitud Venta correccion item directo ' . $itemId . ': ' . $error->getMessage());
        }
    }

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$select=id&$expand=fields&$top=200';
    $candidato = null;

    while ($url !== '') {
        $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (!rcCoincide($fields, $folio, $correoUsuario)) continue;

            $numero = max(1, (int) ($fields['Componente_Numero'] ?? 1));
            $principal = (bool) ($fields['Es_Principal'] ?? false) || $numero === 1;
            if ($principal) return $item;
            if ($candidato === null) $candidato = $item;
        }
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
    }

    if (is_array($candidato)) return $candidato;
    svResponderError(404, 'CORRECTION_NOT_FOUND', 'No se encontro la solicitud indicada.');
}

/** @param array<string,mixed> $fields */
function rcCoincide(array $fields, string $folio, string $correoUsuario): bool
{
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) return false;

    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    return $title === $folio || $grupo === $folio || str_starts_with($title, $folio . '-');
}

/** @return array<int,array<string,mixed>> */
function rcObtenerGrupo(string $token, string $siteId, string $listId, string $grupo, string $folio, array $principal): array
{
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$select=id&$expand=fields&$top=200';

    while ($url !== '') {
        $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $grupoItem = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
            if ($grupoItem === $grupo || $title === $folio || str_starts_with($title, $folio . '-')) {
                $items[] = $item;
            }
        }
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
    }

    return $items ?: [$principal];
}

/** @param array<int,array<string,mixed>> $items @return array<int,array<string,mixed>> */
function rcConstruirComponentesDetalle(array $items): array
{
    usort($items, static function (array $a, array $b): int {
        $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
        $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        $na = max(1, (int) ($fa['Componente_Numero'] ?? 1));
        $nb = max(1, (int) ($fb['Componente_Numero'] ?? 1));
        return $na <=> $nb;
    });

    $resultado = [];
    foreach ($items as $index => $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $tipo = strtoupper(trim((string) rcPrimerValor($fields, ['Tipo_Componente', 'Tipo_Solicitud', 'field_56'])));
        $operacion = strtoupper(trim((string) rcPrimerValor($fields, ['field_47'])));
        $clave = strtoupper(trim((string) rcPrimerValor($fields, ['field_4'])));
        $numeroServicio = '';
        if ($tipo === 'SERVICIO' && substr_count($clave, '-') >= 2) {
            $partes = explode('-', $clave);
            $numeroServicio = trim((string) end($partes));
        }

        $sucursal = strtoupper(trim((string) rcPrimerValor($fields, ['field_49'])));
        if ($tipo === 'LOTE' || $tipo === 'NICHO') $sucursal = 'PARQUE';
        if ($tipo === 'SERVICIO' && !in_array($sucursal, ['CHURUBUSCO', 'AGUA FRIA'], true)) $sucursal = '';

        $numero = max(1, (int) ($fields['Componente_Numero'] ?? ($index + 1)));
        $resultado[] = [
            'componenteNumero' => $numero,
            'esPrincipal' => (bool) ($fields['Es_Principal'] ?? false) || $numero === 1,
            'tipoSolicitud' => $tipo,
            'tipoOperacion' => $operacion !== '' ? $operacion : 'PREVISION',
            'tipoVentaProcap' => (string) rcPrimerValor($fields, ['field_48', 'field_51']),
            'sucursal' => $sucursal,
            'servicioTipo' => (string) rcPrimerValor($fields, ['field_52']),
            'servicioAtaud' => (string) rcPrimerValor($fields, ['field_53']),
            'servicioUrna' => (string) rcPrimerValor($fields, ['field_54']),
            'servicioDuracion' => (string) rcPrimerValor($fields, ['field_55']),
            'servicioNumero' => $numeroServicio,
            'servicioClave' => $tipo === 'SERVICIO' ? $clave : '',
            'propiedadTipo' => (string) rcPrimerValor($fields, ['Propiedad_Subtipo']),
            'propiedadSeccion' => (string) rcPrimerValor($fields, ['field_57']),
            'propiedadManzana' => (string) rcPrimerValor($fields, ['field_58']),
            'propiedadNumero' => (string) rcPrimerValor($fields, ['field_59']),
            'propiedadClave' => ($tipo === 'LOTE' || $tipo === 'NICHO')
                ? (string) (rcPrimerValor($fields, ['field_4', 'field_60']) ?: '')
                : (string) rcPrimerValor($fields, ['field_60']),
            'precioBaseComponente' => (float) (rcPrimerValor($fields, ['Precio_Base_Componente', 'field_76', 'field_63']) ?: 0),
            'montoComponente' => (float) (rcPrimerValor($fields, ['Monto_Componente', 'field_63']) ?: 0),
        ];
    }

    return $resultado;
}

/** @param array<string,mixed> $fields */
function rcPrimerValor(array $fields, array $columnas)
{
    foreach ($columnas as $columna) {
        if (!array_key_exists($columna, $fields)) continue;
        $valor = $fields[$columna];
        if ($valor !== null && $valor !== '') return $valor;
    }
    return '';
}

/** @param array<string,mixed> $fields */
function rcMotivo(array $fields): string
{
    foreach (['VoBo_Motivo_Correccion', 'VoBo_Observaciones', 'Motivo_Correccion'] as $field) {
        $motivo = trim((string) ($fields[$field] ?? ''));
        if ($motivo !== '') return rcTextoPlano($motivo);
    }
    return '';
}

function rcTextoPlano(string $valor): string
{
    $texto = preg_replace('/<\s*br\s*\/?>/i', "\n", $valor);
    $texto = preg_replace('/<\s*\/(?:p|div|li)\s*>/i', "\n", is_string($texto) ? $texto : $valor);
    $texto = strip_tags(is_string($texto) ? $texto : $valor);
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = str_replace("\xc2\xa0", ' ', $texto);
    $texto = preg_replace('/[ \t]+/', ' ', $texto);
    $texto = preg_replace('/\s*\n\s*/', "\n", is_string($texto) ? $texto : '');
    $texto = preg_replace('/\n{3,}/', "\n\n", is_string($texto) ? $texto : '');
    return trim(is_string($texto) ? $texto : '');
}

function rcObtenerDriveExpedientes(string $token, string $siteId): string
{
    $drives = svCurlJson(
        'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name',
        'GET',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    foreach (($drives['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) {
            $id = trim((string) ($drive['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas.');
}

function rcExisteArchivoExpediente(string $token, string $driveId, string $folio, string $archivo): bool
{
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path;
    try {
        $item = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        return trim((string) ($item['id'] ?? '')) !== '';
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), 'HTTP 404')) return false;
        throw $error;
    }
}

/** @param array<string,mixed> $fields */
function rcActualizarCampos(string $token, string $siteId, string $listId, string $itemId, array $fields): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '/fields';
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la reapertura de la solicitud.');

    svCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);
}