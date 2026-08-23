<?php

declare(strict_types=1);

/**
 * Utilidades de correo para Solicitud de Venta.
 * Requiere _common.php para svGraphToken(), pero no modifica el flujo actual.
 */

/** @return array{sender:string} */
function svNotificacionesConfig(): array
{
    $configPath = '/home/juanpab1/portal-config/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('No se encontro la configuracion privada del portal.');
    }

    $raw = require $configPath;
    if (!is_array($raw)) {
        throw new RuntimeException('La configuracion privada del portal no es valida.');
    }

    $sender = strtolower(trim((string) ($raw['solicitud_notification_sender'] ?? '')));
    if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('solicitud_notification_sender no esta configurado correctamente.');
    }

    return ['sender' => $sender];
}

/** @param string[] $correos @return string[] */
function svNormalizarDestinatarios(array $correos): array
{
    $resultado = [];
    foreach ($correos as $correo) {
        $correo = strtolower(trim((string) $correo));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) continue;
        $resultado[$correo] = true;
    }
    return array_keys($resultado);
}

/**
 * @param string[] $destinatarios
 * @param array<int,array{name:string,contentType:string,contentBytes:string}> $adjuntos
 */
function svEnviarCorreoGraph(
    string $graphToken,
    string $sender,
    array $destinatarios,
    string $asunto,
    string $html,
    array $adjuntos = []
): void {
    $sender = strtolower(trim($sender));
    if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El remitente de notificaciones no es valido.');
    }

    $destinatarios = svNormalizarDestinatarios($destinatarios);
    if (!$destinatarios) {
        throw new RuntimeException('No hay destinatarios validos para el correo.');
    }

    $toRecipients = [];
    foreach ($destinatarios as $correo) {
        $toRecipients[] = ['emailAddress' => ['address' => $correo]];
    }

    $message = [
        'subject' => $asunto,
        'body' => [
            'contentType' => 'HTML',
            'content' => $html,
        ],
        'toRecipients' => $toRecipients,
    ];

    if ($adjuntos) {
        $message['attachments'] = [];
        foreach ($adjuntos as $adjunto) {
            $nombre = trim((string) ($adjunto['name'] ?? ''));
            $contentType = trim((string) ($adjunto['contentType'] ?? 'application/octet-stream'));
            $contentBytes = trim((string) ($adjunto['contentBytes'] ?? ''));
            if ($nombre === '' || $contentBytes === '') continue;
            $message['attachments'][] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $nombre,
                'contentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
                'contentBytes' => $contentBytes,
            ];
        }
    }

    $payload = json_encode([
        'message' => $message,
        'saveToSentItems' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('No fue posible construir el correo de Microsoft Graph.');
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No fue posible inicializar el envio de correo.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $graphToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('El envio de correo fallo: ' . $curlError);
    }

    if (!in_array($httpCode, [200, 202, 204], true)) {
        $detalle = '';
        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) {
            $detalle = trim((string) ($decoded['error']['message'] ?? $decoded['error_description'] ?? ''));
        }
        throw new RuntimeException(
            'Microsoft Graph respondio HTTP ' . $httpCode . ($detalle !== '' ? ': ' . $detalle : '.')
        );
    }
}
