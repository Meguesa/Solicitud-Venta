<?php

declare(strict_types=1);

/**
 * Calcula el siguiente folio visible de Solicitud de Venta a partir de los
 * folios ya asignados en SharePoint. El numero se reinicia automaticamente al
 * cambiar de anio porque solo se consideran titulos SV-AAAA-NNNNNN del anio
 * actual.
 */
function svSiguienteFolioAnual(string $graphToken, string $siteId, string $listId): string
{
    $zona = new DateTimeZone('America/Monterrey');
    $anio = (int) (new DateTimeImmutable('now', $zona))->format('Y');
    $prefijo = sprintf('SV-%04d-', $anio);
    $maximo = 0;

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$select=id&$expand=fields($select=Title,Es_Principal)&$top=200';

    while ($url !== '') {
        $respuesta = ejecutarCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $graphToken,
            'Accept: application/json',
        ]);

        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
            if (!str_starts_with($title, $prefijo)) continue;

            // Componentes secundarios comparten grupo/folio y no deben consumir
            // otro consecutivo. Si Es_Principal no existe en registros antiguos,
            // el titulo exacto sigue siendo suficiente para detectar el maximo.
            if (array_key_exists('Es_Principal', $fields) && !(bool) $fields['Es_Principal']) continue;

            if (!preg_match('/^SV-' . preg_quote((string) $anio, '/') . '-(\d{6})$/', $title, $match)) continue;
            $maximo = max($maximo, (int) $match[1]);
        }

        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }

    $siguiente = $maximo + 1;
    if ($siguiente > 999999) {
        throw new RuntimeException('Se alcanzo el limite anual de 999999 solicitudes.');
    }

    return sprintf('SV-%04d-%06d', $anio, $siguiente);
}
