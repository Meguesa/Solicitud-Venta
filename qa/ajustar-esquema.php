<?php
declare(strict_types=1);
// Migracion de esquema EXCLUSIVA DE QA. NO modifica produccion.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

require_once rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')
    . '/api/solicitud-venta-qa/_common.php';
$cfg = svConfig();
svUsuarioAutenticado($cfg['tenantId'], $cfg['clientId']);

// Segunda barrera de recursos, independiente de la configuracion QA.
if (!hash_equals('meguesajdjp.sharepoint.com,655df2aa-df51-4e2a-ade5-53bdcd9a3b0c,359048b1-c034-4b49-9cdc-c93beeccc008', $cfg['siteId'])
    || !hash_equals('8494b26b-5bdb-4d60-a993-6f53856f6b6f', $cfg['listId'])) {
    http_response_code(403);
    exit('La configuracion no corresponde al sitio y lista QA autorizados.');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(401);
    exit('Se requiere una sesion del Portal.');
}
if (!isset($_SESSION['svqa_schema_csrf']) || !is_string($_SESSION['svqa_schema_csrf'])) {
    $_SESSION['svqa_schema_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['svqa_schema_csrf'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Metodo no permitido.');
}
function qaSchemaEscape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function qaSchemaColumnas(string $base, string $token): array {
    $names = [];
    $url = $base . '/columns?$select=name,displayName&$top=200';
    for ($i = 0; $i < 10 && $url !== ''; $i++) {
        $out = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($out['value'] ?? []) as $c) {
            if (is_array($c) && is_string($c['name'] ?? null)) $names[$c['name']] = true;
        }
        $next = (string) ($out['@odata.nextLink'] ?? '');
        if ($next !== '' && !str_starts_with($next, $base . '/columns?')) {
            throw new RuntimeException('QA_UNEXPECTED_COLUMN_PAGINATION');
        }
        $url = $next;
    }
    if ($url !== '') throw new RuntimeException('QA_COLUMN_PAGINATION_INCOMPLETE');
    return $names;
}
$columnas = [
    'Cliente_Apellido_Paterno' => ['multiple' => false, 'etiqueta' => 'Apellido paterno'],
    'Cliente_Apellido_Materno' => ['multiple' => false, 'etiqueta' => 'Apellido materno'],
    'Cliente_Antiguedad_Domicilio_Anterior' => ['multiple' => false, 'etiqueta' => 'Antiguedad domicilio anterior'],
    'Cobranza_Motivo_Correccion' => ['multiple' => true, 'etiqueta' => 'Motivo de correccion Cobranza']
];
$mensaje = '';
$esError = false;
$creadas = [];
$existentes = [];
try {
    $token = svGraphToken($cfg['tenantId'], $cfg['clientId'], $cfg['clientSecret']);
    $base = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($cfg['siteId'])
        . '/lists/' . rawurlencode($cfg['listId']);
    $lista = svCurlJson($base . '?$select=id,name', 'GET', [
        'Authorization: Bearer ' . $token, 'Accept: application/json'
    ]);
    if (($lista['id'] ?? '') !== $cfg['listId'] || ($lista['name'] ?? '') !== 'Solicitudes_Venta') {
        throw new RuntimeException('QA_LIST_MISMATCH');
    }
    $existentes = qaSchemaColumnas($base, $token);
    if ($method === 'POST') {
        $origin = rtrim(strtolower((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
        $provided = (string) ($_POST['csrf'] ?? '');
        if ($origin !== 'https://portal.juanpablo.com.mx'
            || $provided === '' || !hash_equals($csrf, $provided)) {
            http_response_code(403);
            exit('Solicitud rechazada: origen o CSRF invalido.');
        }
        $last = (int) ($_SESSION['svqa_schema_last_at'] ?? 0);
        if ($last > 0 && time() - $last < 45) {
            http_response_code(429);
            exit('Evita ejecutar la migracion varias veces consecutivas.');
        }
        $_SESSION['svqa_schema_last_at'] = time();
        session_write_close();
        foreach ($columnas as $name => $def) {
            if (isset($existentes[$name])) continue;
            $text = $def['multiple']
                ? ['allowMultipleLines' => true, 'appendChangesToExistingText' => false,
                   'linesForEditing' => 6, 'textType' => 'plain']
                : ['allowMultipleLines' => false, 'appendChangesToExistingText' => false,
                   'linesForEditing' => 0, 'maxLength' => 255];
            $body = json_encode([
                'name' => $name,
                'description' => 'Campo de prueba aislado Solicitud de Venta QA',
                'enforceUniqueValues' => false,
                'hidden' => false,
                'indexed' => false,
                'text' => $text
            ], JSON_THROW_ON_ERROR);
            $created = svCurlJson($base . '/columns', 'POST', [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json'
            ], $body);
            if (($created['name'] ?? '') !== $name) {
                throw new RuntimeException('QA_COLUMN_NAME_MISMATCH:' . $name);
            }
            $creadas[] = $name;
            $existentes[$name] = true;
        }
        $existentes = qaSchemaColumnas($base, $token);
        $faltantes = array_values(array_filter(array_keys($columnas),
            static fn(string $name): bool => !isset($existentes[$name])));
        if ($faltantes) throw new RuntimeException('QA_POST_VERIFY_MISSING_COLUMNS');
        $mensaje = 'Columnas QA verificadas. Creadas en esta operacion: ' . count($creadas)
            . '. La lista de produccion no fue consultada ni modificada.';
    }
} catch (Throwable $e) {
    error_log('Solicitud Venta QA migracion controlada: ' . $e->getMessage());
    $esError = true;
    $mensaje = 'No se pudo completar la operacion. No amplíes los permisos Graph de la aplicacion.';
    if (str_contains($e->getMessage(), 'HTTP 403')) {
        $mensaje .= ' Microsoft Graph no permite administrar columnas con los permisos actuales; crear los campos mediante un administrador de SharePoint.';
    }
    if ($creadas) $mensaje .= ' Columnas creadas antes del fallo: ' . implode(', ', $creadas) . '.';
}
$faltantes = array_values(array_filter(array_keys($columnas),
    static fn(string $name): bool => !isset($existentes[$name])));
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preparar columnas QA | Solicitud de Venta</title>
<style>
body{font:16px system-ui,-apple-system,sans-serif;background:#f7f4f0;color:#432b25}
main{max-width:760px;margin:6vh auto;padding:20px}section{background:white;border:1px solid #e3d8cf;border-radius:15px;padding:30px}
small{color:#766e69}h1{font-size:27px}li{margin:8px 0}
button{padding:14px 18px;background:#ffc52e;color:#3f2119;border:0;border-radius:9px;font-weight:700;cursor:pointer}
.message{padding:14px;border-radius:8px;background:#e8f5ea}.error{background:#fff0ed}
a{color:#116b52}
</style></head><body><main><section>
<small>ENTORNO PRIVADO / SOLO SHAREPOINT QA</small>
<h1>Preparar estructura de Solicitudes_Venta</h1>
<p>Esta operacion solamente puede agregar las cuatro columnas de prueba indicadas al sitio SolicitudVentaQA.
No modifica registros, no elimina columnas, no envia correos y no toca el sitio productivo.</p>
<?php if ($mensaje !== ''): ?><p class="message <?= $esError ? 'error' : '' ?>"><?= qaSchemaEscape($mensaje) ?></p><?php endif; ?>
<ul>
<?php foreach ($columnas as $name => $def): ?>
<li><?= qaSchemaEscape($name) ?> — <?= qaSchemaEscape($def['multiple'] ? 'Texto de varias lineas' : 'Una linea de texto') ?>
<strong>(<?= isset($existentes[$name]) ? 'PRESENTE' : 'FALTANTE' ?>)</strong></li>
<?php endforeach; ?>
</ul>
<?php if (!$esError && $faltantes): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= qaSchemaEscape($csrf) ?>">
<button type="submit">Crear solo columnas faltantes en QA</button></form>
<?php elseif (!$faltantes): ?><p class="message">Las cuatro columnas ya estan disponibles en QA.</p><?php endif; ?>
<p><a href="/solicitud-venta-qa/diagnostico-esquema.php">Volver al diagnostico de esquema</a></p>
</section></main></body></html>
