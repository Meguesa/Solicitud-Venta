<?php

declare(strict_types=1);

/**
 * Layout fisico v2 para el PDF final de Solicitud de Venta.
 *
 * Reutiliza las fuentes de datos y el motor PDF existentes, pero organiza la
 * informacion como formulario fisico: bandas de seccion, filas compactas y
 * columnas variables. Componentes de la Venta aparece al inicio, antes de
 * General, conforme a la operacion comercial.
 */
final class SvPdfFisicoGrid
{
    private SvPdfDocumento $pdf;
    private ReflectionProperty $y;
    private ReflectionMethod $ensureSpace;
    private ReflectionMethod $rectFillStrokeTop;
    private ReflectionMethod $text;
    private ReflectionMethod $fieldBox;
    private ReflectionMethod $wrap;
    private ReflectionMethod $displayValue;

    private const WIDTH = 595.28;
    private const MARGIN = 34.0;
    private const TOTAL = self::WIDTH - (2 * self::MARGIN);

    public function __construct(SvPdfDocumento $pdf)
    {
        $this->pdf = $pdf;
        $ref = new ReflectionClass(SvPdfDocumento::class);
        $this->y = $ref->getProperty('y');
        $this->ensureSpace = $ref->getMethod('ensureSpace');
        $this->rectFillStrokeTop = $ref->getMethod('rectFillStrokeTop');
        $this->text = $ref->getMethod('text');
        $this->fieldBox = $ref->getMethod('fieldBox');
        $this->wrap = $ref->getMethod('wrap');
        $this->displayValue = $ref->getMethod('displayValue');
    }

    public function section(string $title): void
    {
        $this->ensure(25.0);
        $y = $this->getY();
        $this->rectFillStrokeTop->invoke(
            $this->pdf,
            self::MARGIN,
            $y,
            self::TOTAL,
            18.0,
            [0.86, 0.50, 0.12],
            [0.67, 0.34, 0.05],
            0.55
        );
        $this->text->invoke($this->pdf, self::MARGIN + 8.0, $y + 12.6, strtoupper($title), 8.4, true, [1.0, 1.0, 1.0]);
        $this->setY($y + 21.0);
    }

    public function subBand(string $title): void
    {
        $this->ensure(21.0);
        $y = $this->getY();
        $this->rectFillStrokeTop->invoke(
            $this->pdf,
            self::MARGIN,
            $y,
            self::TOTAL,
            15.0,
            [0.97, 0.90, 0.80],
            [0.76, 0.58, 0.35],
            0.45
        );
        $this->text->invoke($this->pdf, self::MARGIN + 7.0, $y + 10.8, $title, 7.6, true, [0.18, 0.12, 0.06]);
        $this->setY($y + 18.0);
    }

    /**
     * @param array<int,array{0:string,1:string}> $cells
     * @param array<int,float> $weights
     */
    public function row(array $cells, array $weights = []): void
    {
        $clean = [];
        foreach ($cells as $cell) {
            $label = trim((string) ($cell[0] ?? ''));
            $value = trim((string) ($cell[1] ?? ''));
            if ($label === '' && $value === '') continue;
            $clean[] = [$label, $value];
        }
        if (!$clean) return;

        if (count($weights) !== count($clean)) {
            $weights = array_fill(0, count($clean), 1.0);
        }
        $sum = array_sum($weights);
        if ($sum <= 0) $sum = (float) count($weights);

        $gap = 1.2;
        $usable = self::TOTAL - ($gap * (count($clean) - 1));
        $widths = [];
        foreach ($weights as $weight) {
            $widths[] = $usable * ((float) $weight / $sum);
        }

        $maxLines = 1;
        $wrapped = [];
        foreach ($clean as $index => $cell) {
            $width = max(45.0, $widths[$index]);
            $maxChars = max(10, (int) floor($width / 5.6));
            $display = (string) $this->displayValue->invoke($this->pdf, $cell[1]);
            $lines = $this->wrap->invoke($this->pdf, $display, $maxChars);
            if (!is_array($lines) || !$lines) $lines = ['-'];
            $wrapped[$index] = $lines;
            $maxLines = max($maxLines, count($lines));
        }

        $height = max(27.0, 20.0 + (($maxLines - 1) * 8.6));
        $this->ensure($height + 1.5);
        $y = $this->getY();
        $x = self::MARGIN;

        foreach ($clean as $index => $cell) {
            $width = $widths[$index];
            $this->fieldBox->invoke($this->pdf, $x, $y, $width, $height, $cell[0], $wrapped[$index]);
            $x += $width + $gap;
        }
        $this->setY($y + $height + 1.5);
    }

    /** @param array<int,array{0:string,1:string}> $rows */
    public function pairs(array $rows): void
    {
        $clean = array_values(array_filter($rows, static function (array $row): bool {
            return trim((string) ($row[1] ?? '')) !== '';
        }));
        for ($i = 0; $i < count($clean); $i += 2) {
            $a = $clean[$i];
            $b = $clean[$i + 1] ?? null;
            if ($b) $this->row([$a, $b]);
            else $this->row([$a]);
        }
    }

    private function ensure(float $height): void
    {
        $this->ensureSpace->invoke($this->pdf, $height);
    }

    private function getY(): float
    {
        return (float) $this->y->getValue($this->pdf);
    }

    private function setY(float $value): void
    {
        $this->y->setValue($this->pdf, $value);
    }
}

/** @param array<int,array<string,mixed>> $grupo */
function svPdfV2Componentes(SvPdfFisicoGrid $grid, array $grupo): void
{
    $grid->section('Componentes de la Venta');
    foreach ($grupo as $index => $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $numero = (int) ($fields['Componente_Numero'] ?? ($index + 1));
        $tipo = strtoupper(trim((string) ($fields['Tipo_Componente'] ?? $fields['Tipo_Solicitud'] ?? '')));
        $grid->subBand('Componente ' . $numero . ($tipo !== '' ? ' - ' . $tipo : ''));

        $grid->row([
            ['Tipo de componente', svPdfFisicoTexto($tipo)],
            ['Tipo de operación', svPdfFisicoTexto($fields['field_47'] ?? '')],
            ['Sucursal', svPdfFisicoTexto($fields['field_49'] ?? '')],
            ['Tipo de venta ProcaP', svPdfFisicoTexto($fields['field_48'] ?? '')],
        ], [1.05, 1.0, 0.9, 1.35]);

        if ($tipo === 'SERVICIO' || svPdfFisicoTexto($fields['field_52'] ?? '') !== '') {
            $servicio = svPdfFisicoTexto($fields['field_52'] ?? '');
            $ataud = svPdfFisicoTexto($fields['field_53'] ?? '');
            if ($ataud === '' && strtoupper($servicio) === 'CREMACION DIRECTA') $ataud = 'NO APLICA';
            $grid->row([
                ['Servicio funerario', $servicio],
                ['Tipo de ataúd', $ataud],
                ['Urna', svPdfFisicoTexto($fields['field_54'] ?? '')],
                ['Duración del servicio', svPdfFisicoTexto($fields['field_55'] ?? '')],
            ], [1.5, 1.15, 1.05, 1.05]);
            $grid->row([
                ['Clave / referencia', svPdfFisicoTexto($fields['field_4'] ?? '')],
                ['Precio base del componente', svPdfMoneda($fields['Precio_Base_Componente'] ?? 0)],
                ['Monto asignado', svPdfMoneda($fields['Monto_Componente'] ?? 0)],
            ], [1.7, 1.0, 1.0]);
        }

        if (in_array($tipo, ['LOTE', 'NICHO'], true) || svPdfFisicoTexto($fields['field_57'] ?? '') !== '') {
            $grid->row([
                ['Tipo / subtipo', svPdfFisicoTexto($fields['Propiedad_Subtipo'] ?? $fields['field_56'] ?? '')],
                ['Sección', svPdfFisicoTexto($fields['field_57'] ?? '')],
                ['Manzana', svPdfFisicoTexto($fields['field_58'] ?? '')],
                ['Número', svPdfFisicoTexto($fields['field_59'] ?? '')],
            ], [1.6, 1.1, 0.75, 0.75]);
            $grid->row([
                ['Clave de propiedad', svPdfFisicoTexto($fields['field_60'] ?? '')],
                ['Precio base del componente', svPdfMoneda($fields['Precio_Base_Componente'] ?? 0)],
                ['Monto asignado', svPdfMoneda($fields['Monto_Componente'] ?? 0)],
            ], [1.7, 1.0, 1.0]);
        }
    }
}

/**
 * @param array<int,array<string,mixed>> $grupo
 * @param array<string,mixed> $estado
 * @return array{contenido:string,nombre:string,cliente:string,tipoVenta:string}
 */
function svPdfConstruirFinalFisicoV2(
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

    $nombre = svPdfFisicoValor($controles, 'clienteNombres', $principal, ['field_8']);
    $paterno = svPdfFisicoValor($controles, 'clienteApellidoPaterno', $principal, ['Cliente_Apellido_Paterno']);
    $materno = svPdfFisicoValor($controles, 'clienteApellidoMaterno', $principal, ['Cliente_Apellido_Materno']);
    if ($paterno === '' && $materno === '') {
        $apellidos = svPdfFisicoTexto($principal['field_9'] ?? '');
        $clienteEstado = trim($nombre . ' ' . $apellidos);
    } else {
        $clienteEstado = trim($nombre . ' ' . $paterno . ' ' . $materno);
    }
    if ($clienteEstado !== '') $cliente = preg_replace('/\s+/u', ' ', $clienteEstado) ?: $clienteEstado;

    $pdf = new SvPdfDocumento($folio);
    $grid = new SvPdfFisicoGrid($pdf);

    svPdfV2Componentes($grid, $grupo);

    $grid->section('General');
    $grid->row([
        ['Lugar', svPdfFisicoValor($controles, 'lugar', $principal, ['field_3', 'field_49'])],
        ['Fecha', svPdfFisicoValor($controles, 'fechaSolicitud', $principal, ['field_2'], 'date')],
        ['Referencia', svPdfFisicoValor($controles, 'referencia', $principal, ['field_4'])],
    ], [1.8, 1.0, 1.35]);
    $grid->row([
        ['Tipo de solicitud', $tipoPrincipal],
        ['Tipo de operación', $operacionPrincipal],
        ['Tipo de venta ProcaP', $tipoVenta],
    ], [1.0, 1.0, 1.5]);
    $grid->row([
        ['Origen de venta', svPdfFisicoValor($controles, 'origenVenta', $principal, ['field_50'])],
        ['Vendedor', $vendedor],
    ], [1.0, 1.3]);

    $grid->section('Información del cliente');
    $grid->row([
        ['Tipo de ID', svPdfFisicoValor($controles, 'clienteTipoId', $principal, ['Cliente_Tipo_ID'])],
        ['Número de ID', svPdfFisicoValor($controles, 'clienteNumeroId', $principal, ['field_5'])],
        ['R.F.C.', svPdfFisicoValor($controles, 'clienteRfc', $principal, ['field_6'])],
        ['C.U.R.P.', svPdfFisicoValor($controles, 'clienteCurp', $principal, ['field_7'])],
    ], [1.15, 1.0, 0.95, 1.25]);
    $grid->row([
        ['Apellido paterno', $paterno],
        ['Apellido materno', $materno],
        ['Nombre', $nombre],
        ['Edad', svPdfFisicoValor($controles, 'edadCliente', $principal, ['field_11'])],
    ], [1.0, 1.0, 1.35, 0.55]);
    $grid->row([
        ['Fecha de nacimiento', svPdfFisicoValor($controles, 'fechaNacimiento', $principal, ['field_10'], 'date')],
        ['Sexo', svPdfFisicoValor($controles, 'clienteSexo', $principal, ['field_12'])],
        ['Estado civil', svPdfFisicoValor($controles, 'clienteEstadoCivil', $principal, ['field_13'])],
        ['Nacionalidad', svPdfFisicoValor($controles, 'clienteNacionalidad', $principal, ['Cliente_Nacionalidad'])],
    ], [1.25, 0.65, 1.0, 1.05]);
    $grid->row([
        ['Régimen matrimonial', svPdfFisicoValor($controles, 'clienteRegimenMatrimonial', $principal, ['Cliente_Regimen_Matrimonial'])],
        ['Vivienda', svPdfFisicoValor($controles, 'clienteVivienda', $principal, ['field_14'])],
        ['Escolaridad', svPdfFisicoValor($controles, 'clienteEscolaridad', $principal, ['Cliente_Escolaridad'])],
    ], [1.35, 1.0, 1.0]);
    $grid->row([
        ['Domicilio actual', svPdfFisicoValor($controles, 'clienteDomicilio', $principal, ['field_15'])],
        ['Número', svPdfFisicoValor($controles, 'clienteNumero', $principal, ['field_16'])],
        ['Colonia', svPdfFisicoValor($controles, 'clienteColonia', $principal, ['field_17'])],
    ], [2.0, 0.7, 1.3]);
    $grid->row([
        ['Ciudad', svPdfFisicoValor($controles, 'clienteCiudad', $principal, ['Cliente_Ciudad'])],
        ['Municipio', svPdfFisicoValor($controles, 'clienteMunicipio', $principal, ['field_18'])],
        ['Provincia / Estado', svPdfFisicoValor($controles, 'clienteEstado', $principal, ['field_19'])],
        ['C.P.', svPdfFisicoValor($controles, 'clienteCp', $principal, ['field_20'])],
    ], [1.0, 1.0, 1.2, 0.65]);
    $grid->row([
        ['Teléfono', svPdfFisicoValor($controles, 'clienteTelefono', $principal, ['Cliente_Telefono'])],
        ['Celular', svPdfFisicoValor($controles, 'clienteCelular', $principal, ['field_21'])],
        ['Correo electrónico', svPdfFisicoValor($controles, 'clienteCorreo', $principal, ['field_22'])],
    ], [0.9, 0.9, 1.8]);
    $grid->row([
        ['Domicilio anterior', svPdfFisicoValor($controles, 'clienteDomicilioAnterior', $principal, ['Cliente_Domicilio_Anterior'])],
        ['Antigüedad en domicilio anterior', svPdfFisicoValor($controles, 'clienteAntiguedadDomicilioAnterior', $principal, ['Cliente_Antiguedad_Domicilio_Anterior'])],
        ['Número de dependientes', svPdfFisicoValor($controles, 'clienteDependientes', $principal, ['field_23'])],
    ], [1.8, 1.2, 0.8]);
    $grid->row([
        ['Edades de dependientes', svPdfFisicoValor($controles, 'clienteEdadesDependientes', $principal, ['field_24'])],
        ['Cónyuge', svPdfFisicoValor($controles, 'clienteConyuge', $principal, ['field_25'])],
        ['Fecha nacimiento cónyuge', svPdfFisicoValor($controles, 'clienteConyugeFechaNacimiento', $principal, ['Conyuge_Fecha_Nacimiento'], 'date')],
        ['Edad cónyuge', svPdfFisicoValor($controles, 'clienteConyugeEdad', $principal, ['field_26'])],
    ], [1.05, 1.25, 1.05, 0.65]);

    $laborOcupacion = svPdfFisicoValor($controles, 'laboralOcupacion', $principal, ['field_28']);
    $laborEmpresa = svPdfFisicoValor($controles, 'laboralEmpresa', $principal, ['field_27']);
    if ($laborOcupacion !== '' || $laborEmpresa !== '') {
        $grid->section('Información Laboral');
        $grid->row([
            ['Empresa actual', $laborEmpresa],
            ['Ocupación', $laborOcupacion],
        ], [1.7, 1.3]);
        $grid->row([
            ['Domicilio laboral', svPdfFisicoValor($controles, 'laboralDomicilio', $principal, ['field_29'])],
            ['Número', svPdfFisicoValor($controles, 'laboralNumero', $principal, ['field_30'])],
            ['Colonia', svPdfFisicoValor($controles, 'laboralColonia', $principal, ['field_31'])],
        ], [1.8, 0.65, 1.15]);
        $grid->row([
            ['Ciudad', svPdfFisicoValor($controles, 'laboralCiudad', $principal, ['field_32'])],
            ['Municipio', svPdfFisicoValor($controles, 'laboralMunicipio', $principal, ['field_33'])],
            ['Provincia / Estado', svPdfFisicoValor($controles, 'laboralEstado', $principal, ['field_34'])],
            ['C.P.', svPdfFisicoValor($controles, 'laboralCp', $principal, ['field_35'])],
        ], [1.0, 1.0, 1.2, 0.65]);
        $grid->row([
            ['Teléfono', svPdfFisicoValor($controles, 'laboralTelefono', $principal, ['field_36'])],
            ['Ext.', svPdfFisicoValor($controles, 'laboralExtension', $principal, ['field_37'])],
            ['Actividad en la empresa', svPdfFisicoValor($controles, 'laboralActividad', $principal, ['field_38'])],
            ['Sector', svPdfFisicoValor($controles, 'laboralSector', $principal, ['field_39'])],
        ], [1.0, 0.55, 1.5, 0.85]);
        $grid->row([
            ['Antigüedad en su empleo actual', svPdfFisicoValor($controles, 'laboralAntiguedad', $principal, ['field_40'])],
            ['Antigüedad en su empleo anterior', svPdfFisicoValor($controles, 'laboralAntiguedadAnterior', $principal, ['Laboral_Antiguedad_Anterior'])],
        ]);
    }

    if (in_array($tipoPrincipal, ['LOTE', 'NICHO'], true)) {
        $grid->section('Información Financiera y de Crédito');
        $grid->row([
            ['Banco 1 · Nombre', svPdfFisicoValor($controles, 'banco1Nombre', $principal, ['Banco1_Nombre', 'Banco_1_Nombre'])],
            ['Tipo de cuenta', svPdfFisicoValor($controles, 'banco1TipoCuenta', $principal, ['Banco1_Tipo_Cuenta', 'Banco_1_Tipo_Cuenta'])],
            ['Número de cuenta', svPdfFisicoValor($controles, 'banco1NumeroCuenta', $principal, ['Banco1_Numero_Cuenta', 'Banco_1_Numero_Cuenta'])],
        ], [1.2, 1.0, 1.2]);
        $grid->row([
            ['Banco 2 · Nombre', svPdfFisicoValor($controles, 'banco2Nombre', $principal, ['Banco2_Nombre', 'Banco_2_Nombre'])],
            ['Tipo de cuenta', svPdfFisicoValor($controles, 'banco2TipoCuenta', $principal, ['Banco2_Tipo_Cuenta', 'Banco_2_Tipo_Cuenta'])],
            ['Número de cuenta', svPdfFisicoValor($controles, 'banco2NumeroCuenta', $principal, ['Banco2_Numero_Cuenta', 'Banco_2_Numero_Cuenta'])],
        ], [1.2, 1.0, 1.2]);

        $grid->section('Referencias Familiares');
        $grid->row([
            ['Referencia 1 · Nombre', svPdfFisicoValor($controles, 'referencia1Nombre', $principal, ['Referencia1_Nombre', 'Referencia_1_Nombre', 'Referencia_Familiar1_Nombre'])],
            ['Teléfono', svPdfFisicoValor($controles, 'referencia1Telefono', $principal, ['Referencia1_Telefono', 'Referencia_1_Telefono', 'Referencia_Familiar1_Telefono'])],
            ['Celular', svPdfFisicoValor($controles, 'referencia1Celular', $principal, ['Referencia1_Celular', 'Referencia_1_Celular', 'Referencia_Familiar1_Celular'])],
        ], [1.7, 0.9, 0.9]);
        $grid->row([
            ['Referencia 2 · Nombre', svPdfFisicoValor($controles, 'referencia2Nombre', $principal, ['Referencia2_Nombre', 'Referencia_2_Nombre', 'Referencia_Familiar2_Nombre'])],
            ['Teléfono', svPdfFisicoValor($controles, 'referencia2Telefono', $principal, ['Referencia2_Telefono', 'Referencia_2_Telefono', 'Referencia_Familiar2_Telefono'])],
            ['Celular', svPdfFisicoValor($controles, 'referencia2Celular', $principal, ['Referencia2_Celular', 'Referencia_2_Celular', 'Referencia_Familiar2_Celular'])],
        ], [1.7, 0.9, 0.9]);
    }

    $grid->section('Datos Titular Substituto');
    $grid->row([
        ['Nombre', svPdfFisicoValor($controles, 'sustitutoNombre', $principal, ['field_41'])],
        ['Domicilio', svPdfFisicoValor($controles, 'sustitutoDomicilio', $principal, ['field_42'])],
        ['Edad', svPdfFisicoValor($controles, 'sustitutoEdad', $principal, ['field_43'])],
    ], [1.25, 1.7, 0.55]);
    $grid->row([
        ['Teléfono', svPdfFisicoValor($controles, 'sustitutoTelefono', $principal, ['field_44'])],
        ['Parentesco', svPdfFisicoValor($controles, 'sustitutoParentesco', $principal, ['field_45'])],
        ['I.D.', svPdfFisicoValor($controles, 'sustitutoId', $principal, ['field_46'])],
    ], [1.2, 1.0, 1.0]);

    if ($operacionPrincipal === 'USO INMEDIATO') {
        $grid->section('Información de Uso Inmediato');
        $grid->row([
            ['Nombres del finado', svPdfFisicoValor($controles, 'finadoNombres', $principal, ['field_89'])],
            ['Apellidos del finado', svPdfFisicoValor($controles, 'finadoApellidos', $principal, ['field_90'])],
            ['Sexo', svPdfFisicoValor($controles, 'finadoSexo', $principal, ['field_91'])],
            ['Parentesco con titular', svPdfFisicoValor($controles, 'finadoParentescoTitular', $principal, ['field_94'])],
        ], [1.25, 1.25, 0.6, 1.1]);
        $grid->row([
            ['Estatura (m)', svPdfFisicoValor($controles, 'finadoEstatura', $principal, ['field_92'])],
            ['Peso (kg)', svPdfFisicoValor($controles, 'finadoPeso', $principal, ['field_93'])],
            ['Causa de defunción', svPdfFisicoValor($controles, 'finadoCausaDefuncion', $principal, ['field_95'])],
            ['Procedencia', svPdfFisicoValor($controles, 'finadoProcedencia', $principal, ['field_96'])],
        ], [0.7, 0.7, 1.55, 1.05]);
        $grid->row([
            ['Nombres del corresponsable', svPdfFisicoValor($controles, 'uiCorresponsableNombres', $principal, ['field_97'])],
            ['Apellidos del corresponsable', svPdfFisicoValor($controles, 'uiCorresponsableApellidos', $principal, ['field_98'])],
            ['Parentesco con finado', svPdfFisicoValor($controles, 'uiCorresponsableParentesco', $principal, ['field_99'])],
            ['Celular del corresponsable', svPdfFisicoValor($controles, 'uiCorresponsableCelular', $principal, ['field_100'])],
        ], [1.2, 1.2, 1.0, 1.0]);
        $obsUi = svPdfFisicoValor($controles, 'uiObservaciones', $principal, ['field_101']);
        if ($obsUi !== '') $grid->row([['Observaciones de uso inmediato', $obsUi]]);
    }

    $grid->section('Información de la Venta');
    $grid->row([
        ['Tipo de contrato', svPdfFisicoValor($controles, 'tipoContrato', $principal, ['field_51'])],
        ['Paquete / Plan', svPdfFisicoValor($controles, 'paquete', $principal, ['Paquete'])],
    ]);
    $grid->row([['Descripción de la venta', svPdfFisicoValor($controles, 'descripcionVenta', $principal, ['field_61'])]]);

    $grid->section('Importe y Forma de Pago');
    $grid->row([
        ['Forma de pago', svPdfFisicoValor($controles, 'formaPago', $principal, ['field_62'])],
        ['Precio total', svPdfFisicoValor($controles, 'precioTotal', $principal, ['field_63'], 'money')],
        ['Enganche', svPdfFisicoValor($controles, 'enganche', $principal, ['field_64'], 'money')],
        ['Saldo', svPdfFisicoValor($controles, 'saldo', $principal, ['field_65'], 'money')],
    ], [0.85, 1.05, 1.0, 1.0]);
    $grid->row([['Método de pago', svPdfFisicoValor($controles, 'metodoPago', $principal, ['field_69'])]]);

    $formaPago = strtoupper(svPdfFisicoValor($controles, 'formaPago', $principal, ['field_62']));
    if ($formaPago === 'CREDITO' || $formaPago === 'CRÉDITO') {
        $grid->subBand('Financiamiento');
        $grid->row([
            ['Valor total', svPdfFisicoValor($controles, 'precioTotal', $principal, ['field_70', 'field_63'], 'money')],
            ['Inicial', svPdfFisicoValor($controles, 'enganche', $principal, ['field_71', 'field_64'], 'money')],
            ['Saldo', svPdfFisicoValor($controles, 'saldo', $principal, ['field_72', 'field_65'], 'money')],
        ]);
        $grid->row([
            ['Mensualidades', svPdfFisicoValor($controles, 'mensualidades', $principal, ['field_66', 'field_73'])],
            ['Importe mensual', svPdfFisicoValor($controles, 'importeMensual', $principal, ['field_67', 'field_74'], 'money')],
            ['Día de pago', svPdfFisicoValor($controles, 'diaPago', $principal, ['field_68'])],
            ['Fecha de inicio', svPdfFisicoValor($controles, 'fechaPrimerVencimiento', $principal, ['field_75'], 'date')],
        ], [0.9, 1.1, 0.75, 1.25]);
        $grid->row([
            ['Precio de lista', svPdfFisicoValor($controles, 'precioLista', $principal, ['field_76', 'Precio_Base_Componente'], 'money')],
            ['Bonificación', svPdfFisicoValor($controles, 'bonificacion', $principal, ['field_77'], 'money')],
            ['Monto a financiar', svPdfFisicoValor($controles, 'montoFinanciar', $principal, ['field_78'], 'money')],
            ['Interés (%)', svPdfFisicoValor($controles, 'interesFinanciamiento', $principal, ['field_79'])],
        ], [1.1, 0.9, 1.2, 0.75]);
        $grid->row([
            ['Periodo de pagos', svPdfFisicoValor($controles, 'periodoPagos', $principal, ['field_80'])],
            ['Pagos anuales', svPdfFisicoValor($controles, 'pagosAnuales', $principal, ['field_81'])],
            ['Total a pagar', svPdfFisicoValor($controles, 'totalPagar', $principal, ['field_82'], 'money')],
            ['Conformidad', svPdfFisicoValor($controles, 'conformidadFinanciamiento', $principal, ['Financiamiento_Conformidad'], 'bool')],
        ], [1.1, 0.9, 1.15, 0.8]);
    }

    $grid->section('Documentación recibida');
    $pdf->checklist([
        ['Identificación oficial del titular · Frente', svPdfBool($principal['Documento_ID_Titular'] ?? false)],
        ['Identificación oficial del titular · Reverso', svPdfBool($principal['Documento_ID_Titular'] ?? false)],
        ['Identificación del titular substituto · Frente', svPdfBool($principal['Documento_ID_Sustituto'] ?? false)],
        ['Identificación del titular substituto · Reverso', svPdfBool($principal['Documento_ID_Sustituto'] ?? false)],
        ['Comprobante de domicilio', svPdfBool($principal['Documento_Comprobante_Domicilio'] ?? false)],
        ['Comprobante de pago', svPdfBool($principal['Documento_Comprobante_Pago'] ?? false)],
    ]);
    $otros = svPdfFisicoValor($controles, 'documentoOtros', $principal, ['field_88']);
    if ($otros !== '') $grid->row([['Otros documentos', $otros]]);

    $observaciones = svPdfFisicoValor($controles, 'observacionesSolicitud', $principal, ['Observaciones_Solicitud']);
    if ($observaciones !== '') {
        $grid->section('Observaciones de la solicitud');
        $pdf->note($observaciones);
    }

    $pdf->beginBackPage();
    $grid = new SvPdfFisicoGrid($pdf);
    $grid->section('Autorizaciones internas');
    $pdf->approvalBox(
        'Vo.Bo. Comercial',
        strtoupper(svPdfFisicoTexto($principal['VoBo_Estatus'] ?? 'APROBADO')),
        svPdfFisicoTexto($principal['VoBo_Por'] ?? ''),
        svPdfFecha(svPdfFisicoTexto($principal['VoBo_Fecha'] ?? ''))
    );
    $cobranzaPor = trim($cobranzaRevisor) !== '' ? trim($cobranzaRevisor) : svPdfFisicoTexto($principal['Cobranza_Por'] ?? '');
    $cobranzaCuando = trim($cobranzaFecha) !== '' ? trim($cobranzaFecha) : svPdfFisicoTexto($principal['Cobranza_Fecha'] ?? '');
    $pdf->approvalBox('Vo.Bo. de Cobranza', 'APROBADO', $cobranzaPor, svPdfFecha($cobranzaCuando));

    $grid->section('Declaración de conformidad');
    $pdf->note('El cliente manifiesta su conformidad con la información capturada en esta Solicitud de Venta y con las condiciones, importes, componentes y servicios asentados en el expediente digital del folio.');
    $grid->section('Firmas de conformidad');
    $pdf->signaturePair($firmaCliente, $firmaVendedor, $cliente, $vendedor);
    $grid->section('Control del documento');
    $grid->row([
        ['Estatus final', 'APROBADA'],
        ['Fecha de generación', svPdfFecha(gmdate('c'))],
    ]);
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
function svPdfGenerarYGuardarFisicoV2(
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

    $documento = svPdfConstruirFinalFisicoV2(
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
        'layout' => 'fisico-v2',
    ];
}
