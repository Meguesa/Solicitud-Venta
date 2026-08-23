<?php

declare(strict_types=1);

/**
 * Layout del PDF final basado en los titulos visibles de Solicitud de Venta.
 *
 * La fuente prioritaria es _ESTADO_BORRADOR.json porque conserva los valores
 * exactamente como fueron capturados en los controles del formulario. SharePoint
 * se usa como respaldo y para datos de auditoria/estatus/componentes.
 */

/** @return array<string,mixed> */
function svPdfFisicoCargarEstado(string $graphToken, string $driveId, string $folio): array
{
    $path = rawurlencode($folio) . '/_ESTADO_BORRADOR.json';
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . $path . ':/content';
    try {
        $documento = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $graphToken,
            'Accept: application/json',
        ]);
        $estado = $documento['estado'] ?? null;
        return is_array($estado) ? $estado : [];
    } catch (Throwable $error) {
        error_log('Solicitud Venta PDF estado ' . $folio . ': ' . $error->getMessage());
        return [];
    }
}

function svPdfFisicoTexto($value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'SI' : 'NO';
    if (is_array($value) || is_object($value)) return '';
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return is_string($text) ? $text : '';
}

/** @return array{found:bool,value:mixed} */
function svPdfFisicoField(array $fields, array $keys): array
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $fields)) return ['found' => true, 'value' => $fields[$key]];
    }
    $lower = [];
    foreach ($fields as $key => $value) {
        if (is_string($key)) $lower[strtolower($key)] = $value;
    }
    foreach ($keys as $key) {
        $needle = strtolower((string) $key);
        if (array_key_exists($needle, $lower)) return ['found' => true, 'value' => $lower[$needle]];
    }
    return ['found' => false, 'value' => null];
}

/** @return array{found:bool,value:mixed} */
function svPdfFisicoControl(array $controles, string $id): array
{
    if (!isset($controles[$id]) || !is_array($controles[$id])) return ['found' => false, 'value' => null];
    $estado = $controles[$id];
    if (!array_key_exists('valor', $estado)) return ['found' => false, 'value' => null];
    return ['found' => true, 'value' => $estado['valor']];
}

function svPdfFisicoValor(array $controles, string $controlId, array $fields, array $fieldKeys, string $tipo = 'text'): string
{
    $source = $controlId !== '' ? svPdfFisicoControl($controles, $controlId) : ['found' => false, 'value' => null];
    if (!$source['found']) $source = svPdfFisicoField($fields, $fieldKeys);
    if (!$source['found']) return '';

    $value = $source['value'];
    if ($tipo === 'bool') return svPdfBool($value) ? 'SI' : 'NO';
    if ($tipo === 'money') return is_numeric($value) ? svPdfMoneda($value) : svPdfFisicoTexto($value);
    if ($tipo === 'date') return svPdfFecha(svPdfFisicoTexto($value));
    return svPdfFisicoTexto($value);
}

/** @param array<int,array{0:string,1:string}> $rows */
function svPdfFisicoPares(SvPdfDocumento $pdf, array $rows): void
{
    $clean = [];
    foreach ($rows as $row) {
        $label = trim((string) ($row[0] ?? ''));
        $value = trim((string) ($row[1] ?? ''));
        if ($label === '' || $value === '') continue;
        $clean[] = [$label, $value];
    }
    for ($i = 0; $i < count($clean); $i += 2) {
        $a = $clean[$i];
        $b = $clean[$i + 1] ?? ['', ''];
        $pdf->fieldPair($a[0], $a[1], $b[0], $b[1]);
    }
}

/** @param array<int,array{0:string,1:string}> $rows */
function svPdfFisicoSeccionSiHay(SvPdfDocumento $pdf, string $titulo, array $rows): bool
{
    $hay = false;
    foreach ($rows as $row) {
        if (trim((string) ($row[1] ?? '')) !== '') {
            $hay = true;
            break;
        }
    }
    if (!$hay) return false;
    $pdf->section($titulo);
    svPdfFisicoPares($pdf, $rows);
    return true;
}

/** @param array<int,array<string,mixed>> $grupo */
function svPdfFisicoTipoPrincipal(array $grupo): string
{
    $principal = svPdfPrincipal($grupo);
    return strtoupper(trim((string) ($principal['Tipo_Componente'] ?? $principal['Tipo_Solicitud'] ?? $principal['field_56'] ?? '')));
}

/**
 * @param array<int,array<string,mixed>> $grupo
 * @param array<string,mixed> $estado
 * @return array{contenido:string,nombre:string,cliente:string,tipoVenta:string}
 */
function svPdfConstruirFinalFisico(
    string $folio,
    array $grupo,
    array $estado,
    string $cobranzaRevisor = '',
    string $cobranzaFecha = '',
    ?string $firmaCliente = null,
    ?string $firmaVendedor = null
): array {
    if (!$grupo) throw new RuntimeException('La solicitud no contiene componentes para generar el PDF.');

    usort($grupo, static function (array $a, array $b): int {
        $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
        $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        return ((int) ($fa['Componente_Numero'] ?? 0)) <=> ((int) ($fb['Componente_Numero'] ?? 0));
    });

    $principal = svPdfPrincipal($grupo);
    $controles = is_array($estado['controles'] ?? null) ? $estado['controles'] : [];
    $cliente = svPdfNombreCliente($principal);
    $vendedor = svPdfFisicoTexto($principal['Vendedor_Nombre'] ?? '');
    $tipoVenta = svPdfFisicoTexto($principal['field_48'] ?? '');
    $tipoPrincipal = svPdfFisicoTipoPrincipal($grupo);
    $operacionPrincipal = svPdfFisicoTexto($principal['field_47'] ?? '');

    // Cuando existe el estado original, reconstruimos el nombre con los mismos
    // controles visibles de Solicitud de Venta.
    $nombreControl = svPdfFisicoValor($controles, 'clienteNombres', $principal, ['field_8']);
    $paterno = svPdfFisicoValor($controles, 'clienteApellidoPaterno', $principal, ['Cliente_Apellido_Paterno']);
    $materno = svPdfFisicoValor($controles, 'clienteApellidoMaterno', $principal, ['Cliente_Apellido_Materno']);
    if ($paterno === '' && $materno === '') {
        $apellidos = svPdfFisicoTexto($principal['field_9'] ?? '');
    } else {
        $apellidos = trim($paterno . ' ' . $materno);
    }
    $clienteDesdeEstado = trim($nombreControl . ' ' . $apellidos);
    if ($clienteDesdeEstado !== '') $cliente = preg_replace('/\s+/u', ' ', $clienteDesdeEstado) ?: $clienteDesdeEstado;

    $pdf = new SvPdfDocumento($folio);

    // La secuencia reproduce la Solicitud de Venta, no el esquema de SharePoint.
    $pdf->section('General');
    svPdfFisicoPares($pdf, [
        ['Lugar', svPdfFisicoValor($controles, 'lugar', $principal, ['field_3', 'field_49'])],
        ['Fecha', svPdfFisicoValor($controles, 'fechaSolicitud', $principal, ['field_2'], 'date')],
        ['Tipo de solicitud', $tipoPrincipal],
        ['Tipo de operación', $operacionPrincipal],
        ['Tipo de venta ProcaP', $tipoVenta],
        ['Referencia', svPdfFisicoValor($controles, 'referencia', $principal, ['field_4'])],
        ['Origen de venta', svPdfFisicoValor($controles, 'origenVenta', $principal, ['field_50'])],
        ['Vendedor', $vendedor],
    ]);

    $pdf->section('Información del cliente');
    svPdfFisicoPares($pdf, [
        ['Tipo de ID', svPdfFisicoValor($controles, 'clienteTipoId', $principal, ['Cliente_Tipo_ID'])],
        ['Número de ID', svPdfFisicoValor($controles, 'clienteNumeroId', $principal, ['field_5'])],
        ['R.F.C.', svPdfFisicoValor($controles, 'clienteRfc', $principal, ['field_6'])],
        ['C.U.R.P.', svPdfFisicoValor($controles, 'clienteCurp', $principal, ['field_7'])],
        ['Apellido paterno', $paterno !== '' ? $paterno : ($materno === '' ? $apellidos : '')],
        ['Apellido materno', $materno],
        ['Nombre', $nombreControl !== '' ? $nombreControl : svPdfFisicoTexto($principal['field_8'] ?? '')],
        ['Edad', svPdfFisicoValor($controles, 'edadCliente', $principal, ['field_11'])],
        ['Fecha de nacimiento', svPdfFisicoValor($controles, 'fechaNacimiento', $principal, ['field_10'], 'date')],
        ['Sexo', svPdfFisicoValor($controles, 'clienteSexo', $principal, ['field_12'])],
        ['Estado civil', svPdfFisicoValor($controles, 'clienteEstadoCivil', $principal, ['field_13'])],
        ['Nacionalidad', svPdfFisicoValor($controles, 'clienteNacionalidad', $principal, ['Cliente_Nacionalidad'])],
        ['Régimen matrimonial', svPdfFisicoValor($controles, 'clienteRegimenMatrimonial', $principal, ['Cliente_Regimen_Matrimonial'])],
        ['Vivienda', svPdfFisicoValor($controles, 'clienteVivienda', $principal, ['field_14'])],
        ['Escolaridad', svPdfFisicoValor($controles, 'clienteEscolaridad', $principal, ['Cliente_Escolaridad'])],
        ['Domicilio actual', svPdfFisicoValor($controles, 'clienteDomicilio', $principal, ['field_15'])],
        ['Número', svPdfFisicoValor($controles, 'clienteNumero', $principal, ['field_16'])],
        ['Colonia', svPdfFisicoValor($controles, 'clienteColonia', $principal, ['field_17'])],
        ['Ciudad', svPdfFisicoValor($controles, 'clienteCiudad', $principal, ['Cliente_Ciudad'])],
        ['Municipio', svPdfFisicoValor($controles, 'clienteMunicipio', $principal, ['field_18'])],
        ['Provincia / Estado', svPdfFisicoValor($controles, 'clienteEstado', $principal, ['field_19'])],
        ['C.P.', svPdfFisicoValor($controles, 'clienteCp', $principal, ['field_20'])],
        ['Teléfono', svPdfFisicoValor($controles, 'clienteTelefono', $principal, ['Cliente_Telefono'])],
        ['Celular', svPdfFisicoValor($controles, 'clienteCelular', $principal, ['field_21'])],
        ['Correo electrónico', svPdfFisicoValor($controles, 'clienteCorreo', $principal, ['field_22'])],
        ['Domicilio anterior', svPdfFisicoValor($controles, 'clienteDomicilioAnterior', $principal, ['Cliente_Domicilio_Anterior'])],
        ['Antigüedad en domicilio anterior', svPdfFisicoValor($controles, 'clienteAntiguedadDomicilioAnterior', $principal, ['Cliente_Antiguedad_Domicilio_Anterior'])],
        ['Número de dependientes', svPdfFisicoValor($controles, 'clienteDependientes', $principal, ['field_23'])],
        ['Edades de dependientes', svPdfFisicoValor($controles, 'clienteEdadesDependientes', $principal, ['field_24'])],
        ['Cónyuge', svPdfFisicoValor($controles, 'clienteConyuge', $principal, ['field_25'])],
        ['Fecha nacimiento cónyuge', svPdfFisicoValor($controles, 'clienteConyugeFechaNacimiento', $principal, ['Conyuge_Fecha_Nacimiento'], 'date')],
        ['Edad cónyuge', svPdfFisicoValor($controles, 'clienteConyugeEdad', $principal, ['field_26'])],
    ]);

    svPdfFisicoSeccionSiHay($pdf, 'Información Laboral', [
        ['Ocupación', svPdfFisicoValor($controles, 'laboralOcupacion', $principal, ['field_28'])],
        ['Empresa actual', svPdfFisicoValor($controles, 'laboralEmpresa', $principal, ['field_27'])],
        ['Domicilio laboral', svPdfFisicoValor($controles, 'laboralDomicilio', $principal, ['field_29'])],
        ['Número', svPdfFisicoValor($controles, 'laboralNumero', $principal, ['field_30'])],
        ['Colonia', svPdfFisicoValor($controles, 'laboralColonia', $principal, ['field_31'])],
        ['Ciudad', svPdfFisicoValor($controles, 'laboralCiudad', $principal, ['field_32'])],
        ['Municipio', svPdfFisicoValor($controles, 'laboralMunicipio', $principal, ['field_33'])],
        ['Provincia / Estado', svPdfFisicoValor($controles, 'laboralEstado', $principal, ['field_34'])],
        ['C.P.', svPdfFisicoValor($controles, 'laboralCp', $principal, ['field_35'])],
        ['Teléfono', svPdfFisicoValor($controles, 'laboralTelefono', $principal, ['field_36'])],
        ['Ext.', svPdfFisicoValor($controles, 'laboralExtension', $principal, ['field_37'])],
        ['Actividad en la empresa', svPdfFisicoValor($controles, 'laboralActividad', $principal, ['field_38'])],
        ['Sector', svPdfFisicoValor($controles, 'laboralSector', $principal, ['field_39'])],
        ['Antigüedad en su empleo actual', svPdfFisicoValor($controles, 'laboralAntiguedad', $principal, ['field_40'])],
        ['Antigüedad en su empleo anterior', svPdfFisicoValor($controles, 'laboralAntiguedadAnterior', $principal, ['Laboral_Antiguedad_Anterior'])],
    ]);

    svPdfFisicoSeccionSiHay($pdf, 'Datos Titular Substituto', [
        ['Nombre', svPdfFisicoValor($controles, 'sustitutoNombre', $principal, ['field_41'])],
        ['Domicilio', svPdfFisicoValor($controles, 'sustitutoDomicilio', $principal, ['field_42'])],
        ['Edad', svPdfFisicoValor($controles, 'sustitutoEdad', $principal, ['field_43'])],
        ['Teléfono', svPdfFisicoValor($controles, 'sustitutoTelefono', $principal, ['field_44'])],
        ['Parentesco', svPdfFisicoValor($controles, 'sustitutoParentesco', $principal, ['field_45'])],
        ['I.D.', svPdfFisicoValor($controles, 'sustitutoId', $principal, ['field_46'])],
    ]);

    if (in_array($tipoPrincipal, ['LOTE', 'NICHO'], true)) {
        svPdfFisicoSeccionSiHay($pdf, 'Referencias Familiares', [
            ['Referencia 1 · Nombre', svPdfFisicoValor($controles, 'referencia1Nombre', $principal, ['Referencia1_Nombre', 'Referencia_1_Nombre', 'Referencia_Familiar1_Nombre'])],
            ['Teléfono', svPdfFisicoValor($controles, 'referencia1Telefono', $principal, ['Referencia1_Telefono', 'Referencia_1_Telefono', 'Referencia_Familiar1_Telefono'])],
            ['Celular', svPdfFisicoValor($controles, 'referencia1Celular', $principal, ['Referencia1_Celular', 'Referencia_1_Celular', 'Referencia_Familiar1_Celular'])],
            ['Referencia 2 · Nombre', svPdfFisicoValor($controles, 'referencia2Nombre', $principal, ['Referencia2_Nombre', 'Referencia_2_Nombre', 'Referencia_Familiar2_Nombre'])],
            ['Teléfono', svPdfFisicoValor($controles, 'referencia2Telefono', $principal, ['Referencia2_Telefono', 'Referencia_2_Telefono', 'Referencia_Familiar2_Telefono'])],
            ['Celular', svPdfFisicoValor($controles, 'referencia2Celular', $principal, ['Referencia2_Celular', 'Referencia_2_Celular', 'Referencia_Familiar2_Celular'])],
        ]);

        svPdfFisicoSeccionSiHay($pdf, 'Información Financiera y de Crédito', [
            ['Banco 1 · Nombre', svPdfFisicoValor($controles, 'banco1Nombre', $principal, ['Banco1_Nombre', 'Banco_1_Nombre'])],
            ['Tipo de cuenta', svPdfFisicoValor($controles, 'banco1TipoCuenta', $principal, ['Banco1_Tipo_Cuenta', 'Banco_1_Tipo_Cuenta'])],
            ['Número de cuenta', svPdfFisicoValor($controles, 'banco1NumeroCuenta', $principal, ['Banco1_Numero_Cuenta', 'Banco_1_Numero_Cuenta'])],
            ['Banco 2 · Nombre', svPdfFisicoValor($controles, 'banco2Nombre', $principal, ['Banco2_Nombre', 'Banco_2_Nombre'])],
            ['Tipo de cuenta', svPdfFisicoValor($controles, 'banco2TipoCuenta', $principal, ['Banco2_Tipo_Cuenta', 'Banco_2_Tipo_Cuenta'])],
            ['Número de cuenta', svPdfFisicoValor($controles, 'banco2NumeroCuenta', $principal, ['Banco2_Numero_Cuenta', 'Banco_2_Numero_Cuenta'])],
        ]);
    }

    if ($operacionPrincipal === 'USO INMEDIATO') {
        svPdfFisicoSeccionSiHay($pdf, 'Información de Uso Inmediato', [
            ['Nombres del finado', svPdfFisicoValor($controles, 'finadoNombres', $principal, ['field_89'])],
            ['Apellidos del finado', svPdfFisicoValor($controles, 'finadoApellidos', $principal, ['field_90'])],
            ['Sexo', svPdfFisicoValor($controles, 'finadoSexo', $principal, ['field_91'])],
            ['Estatura (m)', svPdfFisicoValor($controles, 'finadoEstatura', $principal, ['field_92'])],
            ['Peso (kg)', svPdfFisicoValor($controles, 'finadoPeso', $principal, ['field_93'])],
            ['Parentesco con titular', svPdfFisicoValor($controles, 'finadoParentescoTitular', $principal, ['field_94'])],
            ['Causa de defunción', svPdfFisicoValor($controles, 'finadoCausaDefuncion', $principal, ['field_95'])],
            ['Procedencia', svPdfFisicoValor($controles, 'finadoProcedencia', $principal, ['field_96'])],
            ['Nombres del corresponsable', svPdfFisicoValor($controles, 'uiCorresponsableNombres', $principal, ['field_97'])],
            ['Apellidos del corresponsable', svPdfFisicoValor($controles, 'uiCorresponsableApellidos', $principal, ['field_98'])],
            ['Parentesco con finado', svPdfFisicoValor($controles, 'uiCorresponsableParentesco', $principal, ['field_99'])],
            ['Celular del corresponsable', svPdfFisicoValor($controles, 'uiCorresponsableCelular', $principal, ['field_100'])],
            ['Observaciones de uso inmediato', svPdfFisicoValor($controles, 'uiObservaciones', $principal, ['field_101'])],
        ]);
    }

    $pdf->section('Información de la Venta');
    svPdfFisicoPares($pdf, [
        ['Tipo de contrato', svPdfFisicoValor($controles, 'tipoContrato', $principal, ['field_51'])],
        ['Paquete / Plan', svPdfFisicoValor($controles, 'paquete', $principal, ['Paquete'])],
        ['Descripción de la venta', svPdfFisicoValor($controles, 'descripcionVenta', $principal, ['field_61'])],
    ]);

    $pdf->section('Componentes de la venta');
    foreach ($grupo as $index => $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $numero = (int) ($fields['Componente_Numero'] ?? ($index + 1));
        $tipo = strtoupper(trim((string) ($fields['Tipo_Componente'] ?? $fields['Tipo_Solicitud'] ?? '')));
        $rows = [
            ['Tipo de componente', svPdfFisicoTexto($tipo)],
            ['Tipo de operación', svPdfFisicoTexto($fields['field_47'] ?? '')],
            ['Tipo de venta ProcaP', svPdfFisicoTexto($fields['field_48'] ?? '')],
            ['Sucursal', svPdfFisicoTexto($fields['field_49'] ?? '')],
        ];
        if ($tipo === 'SERVICIO' || svPdfFisicoTexto($fields['field_52'] ?? '') !== '') {
            $servicio = svPdfFisicoTexto($fields['field_52'] ?? '');
            $ataud = svPdfFisicoTexto($fields['field_53'] ?? '');
            if ($ataud === '' && strtoupper($servicio) === 'CREMACION DIRECTA') $ataud = 'NO APLICA';
            $rows[] = ['Servicio funerario', $servicio];
            $rows[] = ['Tipo de ataúd', $ataud];
            $rows[] = ['Urna', svPdfFisicoTexto($fields['field_54'] ?? '')];
            $rows[] = ['Duración del servicio', svPdfFisicoTexto($fields['field_55'] ?? '')];
            $rows[] = ['Referencia', svPdfFisicoTexto($fields['field_4'] ?? '')];
        }
        if (in_array($tipo, ['LOTE', 'NICHO'], true) || svPdfFisicoTexto($fields['field_57'] ?? '') !== '') {
            $rows[] = ['Tipo / subtipo', svPdfFisicoTexto($fields['Propiedad_Subtipo'] ?? $fields['field_56'] ?? '')];
            $rows[] = ['Sección', svPdfFisicoTexto($fields['field_57'] ?? '')];
            $rows[] = ['Manzana', svPdfFisicoTexto($fields['field_58'] ?? '')];
            $rows[] = ['Número', svPdfFisicoTexto($fields['field_59'] ?? '')];
            $rows[] = ['Clave de propiedad', svPdfFisicoTexto($fields['field_60'] ?? '')];
        }
        $rows[] = ['Precio base del componente', svPdfMoneda($fields['Precio_Base_Componente'] ?? 0)];
        $rows[] = ['Monto asignado', svPdfMoneda($fields['Monto_Componente'] ?? 0)];
        $pdf->componentCard($rows, 'Componente ' . $numero . ($tipo !== '' ? ' - ' . $tipo : ''));
    }

    $pdf->section('Importe y Forma de Pago');
    svPdfFisicoPares($pdf, [
        ['Forma de pago', svPdfFisicoValor($controles, 'formaPago', $principal, ['field_62'])],
        ['Precio total', svPdfFisicoValor($controles, 'precioTotal', $principal, ['field_63'], 'money')],
        ['Enganche', svPdfFisicoValor($controles, 'enganche', $principal, ['field_64'], 'money')],
        ['Saldo', svPdfFisicoValor($controles, 'saldo', $principal, ['field_65'], 'money')],
        ['Método de pago', svPdfFisicoValor($controles, 'metodoPago', $principal, ['field_69'])],
    ]);

    $formaPago = strtoupper(svPdfFisicoValor($controles, 'formaPago', $principal, ['field_62']));
    if ($formaPago === 'CREDITO' || $formaPago === 'CRÉDITO') {
        $pdf->subSection('Financiamiento');
        svPdfFisicoPares($pdf, [
            ['Mensualidades', svPdfFisicoValor($controles, 'mensualidades', $principal, ['field_66', 'field_73'])],
            ['Importe mensual', svPdfFisicoValor($controles, 'importeMensual', $principal, ['field_67', 'field_74'], 'money')],
            ['Día de pago', svPdfFisicoValor($controles, 'diaPago', $principal, ['field_68'])],
            ['Fecha de inicio', svPdfFisicoValor($controles, 'fechaPrimerVencimiento', $principal, ['field_75'], 'date')],
            ['Precio de lista', svPdfFisicoValor($controles, 'precioLista', $principal, ['field_76', 'Precio_Base_Componente'], 'money')],
            ['Bonificación', svPdfFisicoValor($controles, 'bonificacion', $principal, ['field_77'], 'money')],
            ['Monto a financiar', svPdfFisicoValor($controles, 'montoFinanciar', $principal, ['field_78'], 'money')],
            ['Interés de financiamiento (%)', svPdfFisicoValor($controles, 'interesFinanciamiento', $principal, ['field_79'])],
            ['Periodo de pagos', svPdfFisicoValor($controles, 'periodoPagos', $principal, ['field_80'])],
            ['Pagos anuales', svPdfFisicoValor($controles, 'pagosAnuales', $principal, ['field_81'])],
            ['Total a pagar', svPdfFisicoValor($controles, 'totalPagar', $principal, ['field_82'], 'money')],
            ['Conformidad de financiamiento', svPdfFisicoValor($controles, 'conformidadFinanciamiento', $principal, ['Financiamiento_Conformidad'], 'bool')],
        ]);
    }

    $pdf->section('Documentación recibida');
    $pdf->checklist([
        ['Identificación oficial del titular · Frente', svPdfBool($principal['Documento_ID_Titular'] ?? false)],
        ['Identificación oficial del titular · Reverso', svPdfBool($principal['Documento_ID_Titular'] ?? false)],
        ['Identificación del titular substituto · Frente', svPdfBool($principal['Documento_ID_Sustituto'] ?? false)],
        ['Identificación del titular substituto · Reverso', svPdfBool($principal['Documento_ID_Sustituto'] ?? false)],
        ['Comprobante de domicilio', svPdfBool($principal['Documento_Comprobante_Domicilio'] ?? false)],
        ['Comprobante de pago', svPdfBool($principal['Documento_Comprobante_Pago'] ?? false)],
    ]);
    $otros = svPdfFisicoValor($controles, 'documentoOtros', $principal, ['field_88']);
    if ($otros !== '') $pdf->labelValue('Otros documentos', $otros);

    $observaciones = svPdfFisicoValor($controles, 'observacionesSolicitud', $principal, ['Observaciones_Solicitud']);
    if ($observaciones !== '') {
        $pdf->section('Observaciones de la solicitud');
        $pdf->note($observaciones);
    }

    // Reverso: conserva aprobaciones y firmas reales del expediente.
    $pdf->beginBackPage();
    $pdf->section('Autorizaciones internas');
    $pdf->approvalBox(
        'Vo.Bo. Comercial',
        strtoupper(svPdfFisicoTexto($principal['VoBo_Estatus'] ?? 'APROBADO')),
        svPdfFisicoTexto($principal['VoBo_Por'] ?? ''),
        svPdfFecha(svPdfFisicoTexto($principal['VoBo_Fecha'] ?? ''))
    );

    $cobranzaPor = trim($cobranzaRevisor) !== '' ? trim($cobranzaRevisor) : svPdfFisicoTexto($principal['Cobranza_Por'] ?? '');
    $cobranzaCuando = trim($cobranzaFecha) !== '' ? trim($cobranzaFecha) : svPdfFisicoTexto($principal['Cobranza_Fecha'] ?? '');
    $pdf->approvalBox('Vo.Bo. de Cobranza', 'APROBADO', $cobranzaPor, svPdfFecha($cobranzaCuando));

    $pdf->section('Declaración de conformidad');
    $pdf->note('El cliente manifiesta su conformidad con la información capturada en esta Solicitud de Venta y con las condiciones asentadas en el expediente digital del folio.');

    $pdf->section('Firmas de conformidad');
    $pdf->signaturePair($firmaCliente, $firmaVendedor, $cliente, $vendedor);

    $pdf->section('Control del documento');
    $pdf->fieldPair('Estatus final', 'APROBADA', 'Fecha de generación', svPdfFecha(gmdate('c')));
    $pdf->note('Documento final generado por el Portal Interno de Jardines de Juan Pablo. La documentación y las evidencias originales permanecen en el expediente electrónico asociado al folio.');

    return [
        'contenido' => $pdf->build(),
        'nombre' => 'SOLICITUD_FINAL_' . $folio . '.pdf',
        'cliente' => $cliente,
        'tipoVenta' => $tipoVenta,
    ];
}

/**
 * @param array<int,array<string,mixed>> $grupo
 * @param array<string,string> $config
 * @return array<string,mixed>
 */
function svPdfGenerarYGuardarFisico(
    string $folio,
    array $grupo,
    string $graphToken,
    array $config,
    string $cobranzaRevisor = '',
    string $cobranzaFecha = ''
): array {
    $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
    svPdfAsegurarCarpeta($graphToken, $driveId, $folio);

    $estado = svPdfFisicoCargarEstado($graphToken, $driveId, $folio);
    $firmaCliente = svPdfDescargarFirma($graphToken, $driveId, $folio, 'FIRMA_CLIENTE');
    $firmaVendedor = svPdfDescargarFirma($graphToken, $driveId, $folio, 'FIRMA_VENDEDOR');

    $documento = svPdfConstruirFinalFisico(
        $folio,
        $grupo,
        $estado,
        $cobranzaRevisor,
        $cobranzaFecha,
        $firmaCliente,
        $firmaVendedor
    );

    $item = svPdfSubir($graphToken, $driveId, $folio, $documento['nombre'], $documento['contenido']);
    return [
        'nombre' => $documento['nombre'],
        'contenido' => $documento['contenido'],
        'cliente' => $documento['cliente'],
        'tipoVenta' => $documento['tipoVenta'],
        'driveItemId' => (string) ($item['id'] ?? ''),
        'webUrl' => (string) ($item['webUrl'] ?? ''),
        'firmaClienteIncluida' => is_string($firmaCliente) && $firmaCliente !== '',
        'firmaVendedorIncluida' => is_string($firmaVendedor) && $firmaVendedor !== '',
        'estadoOriginalIncluido' => !empty($estado),
    ];
}
