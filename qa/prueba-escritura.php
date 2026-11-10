<?php
declare(strict_types=1);

// Prueba manual QA con escritura y borrado inmediatos. Nunca usar en produccion.
// Exclusiva para la cuenta de Sistemas con sesion SSO del Portal.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

require_once rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')
    . '/api/solicitud-venta-qa/_common.php';

$cfg = svConfig();
svUsuarioAutenticado($cfg['tenantId'], $cfg['clientId']);
if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(401);
    exit('La sesion del Portal no esta disponible.');
}
if (empty($_SESSION['sv_qa_probe_csrf']) || !is_string($_SESSION['sv_qa_probe_csrf'])) {
    $_SESSION['sv_qa_probe_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['sv_qa_probe_csrf'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Metodo no permitido.');
}

$result = '';
$variant = 'neutral';

if ($method === 'POST') {
    $origin = rtrim(strtolower((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    $provided = (string) ($_POST['qa_csrf'] ?? '');
    if ($origin !== 'https://portal.juanpablo.com.mx'
        || $provided === '' || !hash_equals($csrf, $provided)) {
        http_response_code(403);
        exit('Solicitud de prueba rechazada por seguridad.');
    }

    $last = (int) ($_SESSION['sv_qa_probe_last_at'] ?? 0);
    if ($last > 0 && time() - $last < 30) {
        http_response_code(429);
        exit('La prueba ya se ejecuto recientemente. Evita repetirla durante 30 segundos.');
    }
    $_SESSION['sv_qa_probe_last_at'] = time();
    session_write_close();

    // La configuracion debe cumplir los cuatro IDs exactos y banderas QA.
    $token = '';
    $driveId = '';
    $itemId = '';
    $created = false;
    $cleanupOk = false;
    $fileName = '_SVQA_PRUEBA_' . bin2hex(random_bytes(12)) . '.txt';

    try {
        $token = svGraphToken($cfg['tenantId'], $cfg['clientId'], $cfg['clientSecret']);
        $siteId = $cfg['siteId'];
        $drives = svCurlJson(
            'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
                . '/drives?$select=id,name',
            'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']
        );
        foreach (($drives['value'] ?? []) as $drive) {
            if (is_array($drive) && ($drive['name'] ?? '') === 'Expedientes_Ventas') {
                $driveId = trim((string) ($drive['id'] ?? ''));
                break;
            }
        }
        if ($driveId === '') throw new RuntimeException('QA_DRIVE_NOT_FOUND');

        $content = "Prueba tecnica aislada de Solicitud Venta QA.\n"
            . "Sin nombres de clientes, contratos o informacion financiera.\n";
        $base = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId);
        $pathUrl = $base . '/root:/' . rawurlencode($fileName);
        $createdItem = svCurlJson(
            $pathUrl . ':/content', 'PUT',
            ['Authorization: Bearer ' . $token, 'Content-Type: text/plain; charset=utf-8',
                'Accept: application/json'],
            $content
        );
        $created = true;
        $itemId = trim((string) ($createdItem['id'] ?? ''));
        if ($itemId === '' || ($createdItem['name'] ?? '') !== $fileName) {
            throw new RuntimeException('QA_UPLOAD_INVALID_METADATA');
        }

        $meta = svCurlJson(
            $base . '/items/' . rawurlencode($itemId) . '?$select=id,name,size',
            'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']
        );
        if (($meta['name'] ?? '') !== $fileName
            || (int) ($meta['size'] ?? -1) !== strlen($content)) {
            throw new RuntimeException('QA_UPLOAD_VERIFY_FAILED');
        }
        $result = 'Escritura y lectura de archivo de prueba confirmadas en Expedientes_Ventas.';
        $variant = 'success';
    } catch (Throwable $error) {
        error_log('Solicitud Venta QA prueba de escritura: ' . $error->getMessage());
        $result = 'No se pudo completar la escritura/lectura. Revisa el log PHP de QA.';
        $variant = 'error';
    } finally {
        if ($created && $driveId !== '' && $token !== '') {
            // Eliminar solamente el elemento temporal creado por esta peticion.
            $base = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId);
            $deleteUrl = $itemId !== ''
                ? $base . '/items/' . rawurlencode($itemId)
                : $base . '/root:/' . rawurlencode($fileName);
            try {
                svCurlJson($deleteUrl, 'DELETE',
                    ['Authorization: Bearer ' . $token, 'Accept: application/json']);
                $cleanupOk = true;
            } catch (Throwable $error) {
                error_log('Solicitud Venta QA limpieza pendiente, archivo ' . $fileName
                    . ': ' . $error->getMessage());
            }
        }
    }

    if ($created && !$cleanupOk) {
        $result .= ' ATENCION: la limpieza automatica no pudo confirmarse. '
            . 'Revisar el archivo temporal ' . $fileName . ' en QA y eliminarlo manualmente.';
        $variant = 'error';
    } elseif ($created && $cleanupOk) {
        $result .= ' Limpieza automatica confirmada; no queda archivo temporal.';
    }
}

function qaEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Prueba controlada de escritura | Solicitud de Venta QA</title>
  <style>
    body{margin:0;background:#f7f4f0;color:#432b25;font:16px system-ui,-apple-system,sans-serif}
    main{max-width:700px;margin:5vh auto;padding:24px}
    section{background:white;padding:32px;border-radius:16px;border:1px solid #e3d8cf}
    .tag{font-size:13px;font-weight:700;color:#116b52;letter-spacing:.05em}
    h1{font-size:27px;line-height:1.2}
    p{line-height:1.6}
    button{background:#ffc52e;color:#3f2119;border:0;border-radius:9px;padding:14px 20px;font-weight:800;cursor:pointer;font-size:16px}
    .message{padding:15px;border-radius:9px;margin:18px 0;font-weight:600;overflow-wrap:anywhere}
    .success{background:#e8f5ea}.error{background:#fff0ed}
    small{color:#766e69}
  </style>
</head>
<body><main><section>
  <div class="tag">ENTORNO PRIVADO · QA</div>
  <h1>Prueba de escritura y limpieza</h1>
  <p>Esta prueba crea un archivo de texto sin datos personales dentro de
     <strong>Expedientes_Ventas</strong> del sitio <strong>SolicitudVentaQA</strong>,
     comprueba su existencia y lo elimina inmediatamente.</p>
  <p><strong>No crea solicitudes, no utiliza datos reales y no envía correos.</strong></p>
  <?php if ($result !== ''): ?>
    <div class="message <?= qaEscape($variant) ?>" role="status"><?= qaEscape($result) ?></div>
  <?php endif; ?>
  <form method="post" action="">
    <input type="hidden" name="qa_csrf" value="<?= qaEscape($csrf) ?>">
    <button type="submit">Ejecutar prueba controlada</button>
  </form>
  <p><small>Solo disponible para la cuenta de Sistemas, con una sesión activa del Portal. Evita repetir la prueba consecutivamente.</small></p>
</section></main></body>
</html>
