<?php
declare(strict_types=1);
// Prueba controlada de lista. Solo sitio SolicitudVentaQA, nunca produccion.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/api/solicitud-venta-qa/_common.php';
$cfg = svConfig();
svUsuarioAutenticado($cfg['tenantId'], $cfg['clientId']);
if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(401);
    exit('Sesion de Portal requerida.');
}
if (!isset($_SESSION['svqa_lista_csrf'])) {
    $_SESSION['svqa_lista_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['svqa_lista_csrf'];
$mensaje = '';
$bien = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recibido = (string) ($_POST['csrf'] ?? '');
    $origen = rtrim(strtolower((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    if ($origen !== 'https://portal.juanpablo.com.mx' || !hash_equals($csrf, $recibido)) {
        http_response_code(403);
        exit('Verificacion de solicitud rechazada.');
    }
    if (time() - (int) ($_SESSION['svqa_lista_ultimo'] ?? 0) < 30) {
        http_response_code(429);
        exit('Espera al menos 30 segundos entre pruebas.');
    }
    $_SESSION['svqa_lista_ultimo'] = time();
    session_write_close();
    $id = '';
    $token = '';
    $base = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($cfg['siteId'])
        . '/lists/' . rawurlencode($cfg['listId']);
    $nombre = '_SVQA_' . bin2hex(random_bytes(12));
    $eliminado = false;
    try {
        $token = svGraphToken($cfg['tenantId'], $cfg['clientId'], $cfg['clientSecret']);
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $lista = svCurlJson($base . '?$select=id,name', 'GET', $headers);
        if (($lista['id'] ?? '') !== $cfg['listId'] || ($lista['name'] ?? '') !== 'Solicitudes_Venta') {
            throw new RuntimeException('Recurso QA incorrecto');
        }
        $nuevo = svCurlJson($base . '/items', 'POST',
            [...$headers, 'Content-Type: application/json'],
            json_encode(['fields' => ['Title' => $nombre]], JSON_THROW_ON_ERROR));
        $id = (string) ($nuevo['id'] ?? '');
        if ($id === '' || !ctype_digit($id)) throw new RuntimeException('Falta ID de la fila creada');
        $leido = svCurlJson($base . '/items/' . rawurlencode($id) . '?$expand=fields', 'GET', $headers);
        if (($leido['fields']['Title'] ?? '') !== $nombre) {
            throw new RuntimeException('Lectura QA no coincide');
        }
        $mensaje = 'Creacion y lectura de fila ficticia confirmadas.';
        $bien = true;
    } catch (Throwable $e) {
        error_log('SVQA prueba de lista: ' . $e->getMessage() . ' nombre=' . $nombre);
        $mensaje = 'No se pudo confirmar la prueba de lista. Revisar log QA.';
    } finally {
        if ($id !== '' && ctype_digit($id) && $token !== '') {
            try {
                svCurlJson($base . '/items/' . rawurlencode($id), 'DELETE', $headers);
                $eliminado = true;
            } catch (Throwable $e) {
                error_log('SVQA limpieza fallida ID ' . $id . ': ' . $e->getMessage());
            }
        }
        if (!$eliminado) {
            $bien = false;
            $mensaje .= ' Limpieza sin confirmar. Revisar fila QA Title=' . $nombre . ' ID=' . $id;
        } else {
            $mensaje .= ' Eliminacion automatica confirmada.';
        }
    }
}
function escapar(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prueba de lista | Solicitud Venta QA</title>
<style>body{font:16px system-ui;background:#f8f5f1;color:#432b25}main{max-width:650px;margin:8vh auto;background:white;padding:30px;border-radius:15px}button{background:#ffc52e;border:0;border-radius:8px;padding:14px 18px;font-weight:700;cursor:pointer}.ok{background:#e8f5ea;padding:16px}.error{background:#fff0ed;padding:16px}</style></head>
<body><main><small>ENTORNO PRIVADO · QA</small><h1>Prueba de lista y limpieza</h1>
<p>Crea una fila ficticia en Solicitudes_Venta del sitio SolicitudVentaQA, la lee y la elimina. No utiliza clientes reales ni envia correos.</p>
<?php if ($mensaje !== ''): ?><p class="<?= $bien ? 'ok' : 'error' ?>"><?= escapar($mensaje) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= escapar($csrf) ?>">
<button type="submit">Ejecutar prueba de lista QA</button></form></main></body></html>
