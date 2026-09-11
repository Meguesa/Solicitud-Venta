<?php

declare(strict_types=1);

/**
 * Version juridico-tecnica del consentimiento mostrado al cliente.
 * Si cambia el texto material o el Aviso de Privacidad, debe incrementarse la
 * version y conservarse la evidencia anterior.
 */
function svAvisoPrivacidadVersion(): string
{
    return '2026-09-11';
}

function svAvisoPrivacidadUrl(): string
{
    return 'https://portal.juanpablo.com.mx/privacidad.php';
}

function svConsentimientoPrivacidadVersion(): string
{
    return 'solicitud-venta-2026-09-11-v1';
}

function svConsentimientoPrivacidadTexto(): string
{
    return 'Confirmo que revise la informacion de la Solicitud de Venta y que los datos y condiciones mostrados corresponden a lo acordado. Asimismo, declaro que lei el Aviso de Privacidad de MEGUESA, S.A. de C.V. y autorizo el tratamiento de mis datos personales y, cuando corresponda, datos patrimoniales o financieros para elaborar, evaluar, formalizar, administrar y dar seguimiento a esta Solicitud de Venta.';
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function svConsentimientoPrivacidadCrearEvidencia(
    string $folio,
    string $metodo,
    string $aceptadoUtc,
    string $firmaClienteSha256,
    array $extra = []
): array {
    $texto = svConsentimientoPrivacidadTexto();
    $evidencia = [
        'version' => 1,
        'tipo' => 'CONSENTIMIENTO_PRIVACIDAD_SOLICITUD_VENTA',
        'folio' => strtoupper(trim($folio)),
        'aceptado' => true,
        'aceptadoUtc' => $aceptadoUtc,
        'metodo' => strtoupper(trim($metodo)),
        'avisoPrivacidadVersion' => svAvisoPrivacidadVersion(),
        'avisoPrivacidadUrl' => svAvisoPrivacidadUrl(),
        'consentimientoVersion' => svConsentimientoPrivacidadVersion(),
        'consentimientoTexto' => $texto,
        'consentimientoTextoSha256' => hash('sha256', $texto),
        'firmaClienteSha256' => strtolower(trim($firmaClienteSha256)),
    ];

    foreach ($extra as $key => $value) {
        if (!is_string($key) || $key === '' || array_key_exists($key, $evidencia)) continue;
        $evidencia[$key] = $value;
    }

    return $evidencia;
}

/** @param array<string,mixed> $evidencia */
function svConsentimientoPrivacidadGuardar(
    string $graphToken,
    string $driveId,
    string $folio,
    array $evidencia
): void {
    $body = json_encode(
        $evidencia,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if (!is_string($body)) {
        throw new RuntimeException('No fue posible serializar la evidencia de consentimiento.');
    }
    $body .= "\n";

    $path = rawurlencode(strtoupper(trim($folio))) . '/' . rawurlencode('_CONSENTIMIENTO_PRIVACIDAD.json');
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';

    svCurlJson(
        $url,
        'PUT',
        [
            'Authorization: Bearer ' . $graphToken,
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
        ],
        $body
    );
}

/** @return array<string,mixed> */
function svConsentimientoPrivacidadCargar(
    string $graphToken,
    string $driveId,
    string $folio
): array {
    $path = rawurlencode(strtoupper(trim($folio))) . '/' . rawurlencode('_CONSENTIMIENTO_PRIVACIDAD.json');
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    $data = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
    ]);
    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $evidencia */
function svConsentimientoPrivacidadEsVigente(array $evidencia, string $folio): bool
{
    if (!(bool) ($evidencia['aceptado'] ?? false)) return false;
    if (strtoupper(trim((string) ($evidencia['folio'] ?? ''))) !== strtoupper(trim($folio))) return false;
    if (!hash_equals(svAvisoPrivacidadVersion(), trim((string) ($evidencia['avisoPrivacidadVersion'] ?? '')))) return false;
    if (!hash_equals(svConsentimientoPrivacidadVersion(), trim((string) ($evidencia['consentimientoVersion'] ?? '')))) return false;
    if (trim((string) ($evidencia['aceptadoUtc'] ?? '')) === '') return false;
    if (trim((string) ($evidencia['firmaClienteSha256'] ?? '')) === '') return false;
    return true;
}
