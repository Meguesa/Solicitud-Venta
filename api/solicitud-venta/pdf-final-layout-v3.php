<?php

declare(strict_types=1);

function svPdfV3Titulo(string $title): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($title, 'UTF-8') : strtoupper($title);
}

function svPdfV3NuevaPagina(SvPdfDocumento $pdf): void
{
    $ref = new ReflectionClass(SvPdfDocumento::class);
    $newPage = $ref->getMethod('newPage');
    $newPage->invoke($pdf, false);
}

function svPdfV3Section(SvPdfFisicoGrid $grid, string $title): void
{
    $grid->section(svPdfV3Titulo($title));
}

function svPdfV3ApprovalBox(SvPdfDocumento $pdf, string $title, string $status, string $who, string $when, ?string $png): void
{
    $ref = new ReflectionClass(SvPdfDocumento::class);
    $yProp = $ref->getProperty('y');
    $ensure = $ref->getMethod('ensureSpace');
    $rect = $ref->getMethod('rectStrokeTop');
    $fill = $ref->getMethod('rectFillStrokeTop');
    $text = $ref->getMethod('text');
    $register = $ref->getMethod('registerPng');
    $draw = $ref->getMethod('drawImageFit');

    $ensure->invoke($pdf, 76.0);
    $y = (float) $yProp->getValue($pdf);
    $x = 34.0;
    $w = 527.28;
    $h = 68.0;
    $leftW = 218.0;
    $fill->invoke($pdf, $x, $y, $w, 17.0, [0.93,0.93,0.93], [0.20,0.20,0.20], 0.55);
    $rect->invoke($pdf, $x, $y, $w, $h, [0.20,0.20,0.20], 0.7);
    $text->invoke($pdf, $x + 7.0, $y + 12.0, svPdfV3Titulo($title), 8.2, true, [0.08,0.08,0.08]);
    $text->invoke($pdf, $x + 7.0, $y + 31.0, 'Estatus:', 7.2, true, [0.12,0.12,0.12]);
    $text->invoke($pdf, $x + 52.0, $y + 31.0, $status !== '' ? $status : '-', 7.2, false, [0.12,0.12,0.12]);
    $text->invoke($pdf, $x + 7.0, $y + 45.0, 'Autorizado por:', 7.2, true, [0.12,0.12,0.12]);
    $text->invoke($pdf, $x + 78.0, $y + 45.0, $who !== '' ? mb_substr($who, 0, 38) : '-', 6.6, false, [0.12,0.12,0.12]);
    $text->invoke($pdf, $x + 7.0, $y + 59.0, 'Fecha:', 7.2, true, [0.12,0.12,0.12]);
    $text->invoke($pdf, $x + 40.0, $y + 59.0, $when !== '' ? $when : '-', 7.2, false, [0.12,0.12,0.12]);

    $sigX = $x + $leftW + 10.0;
    $sigW = $w - $leftW - 20.0;
    if (is_string($png) && $png !== '') {
        try {
            $res = $register->invoke($pdf, $png);
            $draw->invoke($pdf, $res, $sigX, $y + 21.0, $sigW, 40.0);
        } catch (Throwable $error) {
            $text->invoke($pdf, $sigX + 8.0, $y + 44.0, 'Firma registrada en expediente', 6.8, false, [0.35,0.35,0.35]);
        }
    } else {
        $text->invoke($pdf, $sigX + 8.0, $y + 44.0, 'Sin firma digital registrada', 6.8, false, [0.45,0.45,0.45]);
    }
    $yProp->setValue($pdf, $y + $h + 7.0);
}

/**
 * Agrega una marca de agua clara a todas las paginas ya construidas y a la
 * pagina activa. Se hace al final para cubrir tambien las paginas creadas de
 * forma automatica cuando una seccion no cabe en la pagina anterior.
 */
function svPdfV3AplicarMarcaPreliminar(SvPdfDocumento $pdf): void
{
    $ref = new ReflectionClass(SvPdfDocumento::class);
    $pagesProp = $ref->getProperty('pages');
    $streamProp = $ref->getProperty('stream');
    $textCommand = $ref->getMethod('pdfTextCommand');

    $watermark = '';
    foreach ([165.0, 300.0, 435.0, 570.0, 705.0] as $y) {
        $watermark .= (string) $textCommand->invoke(
            $pdf,
            52.0,
            $y,
            'DOCUMENTO PRELIMINAR - NO OFICIAL',
            23.0,
            true,
            [0.87, 0.87, 0.87]
        );
    }

    $pages = $pagesProp->getValue($pdf);
    if (is_array($pages)) {
        foreach ($pages as $index => $page) {
            if (!is_array($page)) continue;
            $pages[$index]['content'] = (string) ($page['content'] ?? '') . $watermark;
        }
        $pagesProp->setValue($pdf, $pages);
    }

    $stream = (string) $streamProp->getValue($pdf);
    $streamProp->setValue($pdf, $stream . $watermark);
}

function svPdfConstruirFinalFisicoV3(
    string $folio,
    array $grupo,
    array $estado,
    string $cobranzaRevisor = '',
    string $cobranzaFecha = '',
    ?string $firmaCliente = null,
    ?string $firmaVendedor = null,
    ?string $firmaVoboComercial = null,
    ?string $firmaVoboCobranza = null,
    bool $preliminar = false
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
    $clienteEstado = trim($nombre . ' ' . ($paterno !== '' || $materno !== '' ? trim($paterno . ' ' . $materno) : svPdfFisicoTexto($principal['field_9'] ?? '')));
    if ($clienteEstado !== '') $cliente = preg_replace('/\s+/u', ' ', $clienteEstado) ?: $clienteEstado;

    $pdf = new SvPdfDocumento($folio);
    $grid = new SvPdfFisicoGrid($pdf);

    svPdfV2Componentes($grid, $grupo);

    svPdfV3Section($grid, 'General');
    $grid->row([
        ['Lugar', svPdfFisicoValor($controles, 'lugar', $principal, ['field_3', 'field_49'])],
        ['Fecha', svPdfFisicoValor($controles, 'fechaSolicitud', $principal, ['field_2'], 'date')],
    ], [1.8, 1.0]);
    $grid->row([
        ['Tipo de solicitud', $tipoPrincipal],
        ['Tipo de operación', $operacionPrincipal],
        ['Tipo de venta ProcaP', $tipoVenta],
    ], [1.0,1.0,1.5]);
    $grid->row([
        ['Origen de venta', svPdfFisicoValor($controles, 'origenVenta', $principal, ['field_50'])],
        ['Vendedor', $vendedor],
    ], [1.0,1.3]);

    svPdfV3Section($grid, 'Información del cliente');
    $grid->row([
        ['Tipo de ID', svPdfFisicoValor($controles, 'clienteTipoId', $principal, ['Cliente_Tipo_ID'])],
        ['Número de ID', svPdfFisicoValor($controles, 'clienteNumeroId', $principal, ['field_5'])],
        ['R.F.C.', svPdfFisicoValor($controles, 'clienteRfc', $principal, ['field_6'])],
        ['C.U.R.P.', svPdfFisicoValor($controles, 'clienteCurp', $principal, ['field_7'])],
    ], [1.15,1.0,0.95,1.25]);
    $grid->row([['Apellido paterno',$paterno],['Apellido materno',$materno],['Nombre',$nombre],['Edad',svPdfFisicoValor($controles,'edadCliente',$principal,['field_11'])]],[1,1,1.35,.55]);
    $grid->row([['Fecha de nacimiento',svPdfFisicoValor($controles,'fechaNacimiento',$principal,['field_10'],'date')],['Sexo',svPdfFisicoValor($controles,'clienteSexo',$principal,['field_12'])],['Estado civil',svPdfFisicoValor($controles,'clienteEstadoCivil',$principal,['field_13'])],['Nacionalidad',svPdfFisicoValor($controles,'clienteNacionalidad',$principal,['Cliente_Nacionalidad'])]],[1.25,.65,1,1.05]);
    $grid->row([['Régimen matrimonial',svPdfFisicoValor($controles,'clienteRegimenMatrimonial',$principal,['Cliente_Regimen_Matrimonial'])],['Vivienda',svPdfFisicoValor($controles,'clienteVivienda',$principal,['field_14'])],['Escolaridad',svPdfFisicoValor($controles,'clienteEscolaridad',$principal,['Cliente_Escolaridad'])]],[1.35,1,1]);
    $grid->row([['Domicilio actual',svPdfFisicoValor($controles,'clienteDomicilio',$principal,['field_15'])],['Número',svPdfFisicoValor($controles,'clienteNumero',$principal,['field_16'])],['Colonia',svPdfFisicoValor($controles,'clienteColonia',$principal,['field_17'])]],[2,.7,1.3]);
    $grid->row([['Ciudad',svPdfFisicoValor($controles,'clienteCiudad',$principal,['Cliente_Ciudad'])],['Municipio',svPdfFisicoValor($controles,'clienteMunicipio',$principal,['field_18'])],['Provincia / Estado',svPdfFisicoValor($controles,'clienteEstado',$principal,['field_19'])],['C.P.',svPdfFisicoValor($controles,'clienteCp',$principal,['field_20'])]],[1,1,1.2,.65]);
    $grid->row([['Teléfono',svPdfFisicoValor($controles,'clienteTelefono',$principal,['Cliente_Telefono'])],['Celular',svPdfFisicoValor($controles,'clienteCelular',$principal,['field_21'])],['Correo electrónico',svPdfFisicoValor($controles,'clienteCorreo',$principal,['field_22'])]],[.9,.9,1.8]);
    $grid->row([['Domicilio anterior',svPdfFisicoValor($controles,'clienteDomicilioAnterior',$principal,['Cliente_Domicilio_Anterior'])],['Antigüedad en domicilio anterior',svPdfFisicoValor($controles,'clienteAntiguedadDomicilioAnterior',$principal,['Cliente_Antiguedad_Domicilio_Anterior'])],['Número de dependientes',svPdfFisicoValor($controles,'clienteDependientes',$principal,['field_23'])]],[1.8,1.2,.8]);
    $grid->row([['Edades de dependientes',svPdfFisicoValor($controles,'clienteEdadesDependientes',$principal,['field_24'])],['Cónyuge',svPdfFisicoValor($controles,'clienteConyuge',$principal,['field_25'])],['Fecha nacimiento cónyuge',svPdfFisicoValor($controles,'clienteConyugeFechaNacimiento',$principal,['Conyuge_Fecha_Nacimiento'],'date')],['Edad cónyuge',svPdfFisicoValor($controles,'clienteConyugeEdad',$principal,['field_26'])]],[1.05,1.25,1.05,.65]);

    $laborOcupacion = svPdfFisicoValor($controles,'laboralOcupacion',$principal,['field_28']);
    $laborEmpresa = svPdfFisicoValor($controles,'laboralEmpresa',$principal,['field_27']);
    if ($laborOcupacion !== '' || $laborEmpresa !== '') {
        svPdfV3Section($grid,'Información Laboral');
        $grid->row([['Empresa actual',$laborEmpresa],['Ocupación',$laborOcupacion]],[1.7,1.3]);
        $grid->row([['Domicilio laboral',svPdfFisicoValor($controles,'laboralDomicilio',$principal,['field_29'])],['Número',svPdfFisicoValor($controles,'laboralNumero',$principal,['field_30'])],['Colonia',svPdfFisicoValor($controles,'laboralColonia',$principal,['field_31'])]],[1.8,.65,1.15]);
        $grid->row([['Ciudad',svPdfFisicoValor($controles,'laboralCiudad',$principal,['field_32'])],['Municipio',svPdfFisicoValor($controles,'laboralMunicipio',$principal,['field_33'])],['Provincia / Estado',svPdfFisicoValor($controles,'laboralEstado',$principal,['field_34'])],['C.P.',svPdfFisicoValor($controles,'laboralCp',$principal,['field_35'])]],[1,1,1.2,.65]);
        $grid->row([['Teléfono',svPdfFisicoValor($controles,'laboralTelefono',$principal,['field_36'])],['Ext.',svPdfFisicoValor($controles,'laboralExtension',$principal,['field_37'])],['Actividad en la empresa',svPdfFisicoValor($controles,'laboralActividad',$principal,['field_38'])],['Sector',svPdfFisicoValor($controles,'laboralSector',$principal,['field_39'])]],[1,.55,1.5,.85]);
        $grid->row([['Antigüedad en su empleo actual',svPdfFisicoValor($controles,'laboralAntiguedad',$principal,['field_40'])],['Antigüedad en su empleo anterior',svPdfFisicoValor($controles,'laboralAntiguedadAnterior',$principal,['Laboral_Antiguedad_Anterior'])]]);
    }

    if (in_array($tipoPrincipal,['LOTE','NICHO'],true)) {
        svPdfV3NuevaPagina($pdf);
        $grid = new SvPdfFisicoGrid($pdf);
        svPdfV3Section($grid,'Información Financiera y de Crédito');
        $grid->row([['Banco 1 · Nombre',svPdfFisicoValor($controles,'banco1Nombre',$principal,['Banco1_Nombre','Banco_1_Nombre'])],['Tipo de cuenta',svPdfFisicoValor($controles,'banco1TipoCuenta',$principal,['Banco1_Tipo_Cuenta','Banco_1_Tipo_Cuenta'])],['Número de cuenta',svPdfFisicoValor($controles,'banco1NumeroCuenta',$principal,['Banco1_Numero_Cuenta','Banco_1_Numero_Cuenta'])]],[1.2,1,1.2]);
        $grid->row([['Banco 2 · Nombre',svPdfFisicoValor($controles,'banco2Nombre',$principal,['Banco2_Nombre','Banco_2_Nombre'])],['Tipo de cuenta',svPdfFisicoValor($controles,'banco2TipoCuenta',$principal,['Banco2_Tipo_Cuenta','Banco_2_Tipo_Cuenta'])],['Número de cuenta',svPdfFisicoValor($controles,'banco2NumeroCuenta',$principal,['Banco2_Numero_Cuenta','Banco_2_Numero_Cuenta'])]],[1.2,1,1.2]);
        svPdfV3Section($grid,'Referencias Familiares');
        $grid->row([['Referencia 1 · Nombre',svPdfFisicoValor($controles,'referencia1Nombre',$principal,['Referencia1_Nombre','Referencia_1_Nombre','Referencia_Familiar1_Nombre'])],['Teléfono',svPdfFisicoValor($controles,'referencia1Telefono',$principal,['Referencia1_Telefono','Referencia_1_Telefono','Referencia_Familiar1_Telefono'])],['Celular',svPdfFisicoValor($controles,'referencia1Celular',$principal,['Referencia1_Celular','Referencia_1_Celular','Referencia_Familiar1_Celular'])]],[1.7,.9,.9]);
        $grid->row([['Referencia 2 · Nombre',svPdfFisicoValor($controles,'referencia2Nombre',$principal,['Referencia2_Nombre','Referencia_2_Nombre','Referencia_Familiar2_Nombre'])],['Teléfono',svPdfFisicoValor($controles,'referencia2Telefono',$principal,['Referencia2_Telefono','Referencia_2_Telefono','Referencia_Familiar2_Telefono'])],['Celular',svPdfFisicoValor($controles,'referencia2Celular',$principal,['Referencia2_Celular','Referencia_2_Celular','Referencia_Familiar2_Celular'])]],[1.7,.9,.9]);
    }

    svPdfV3Section($grid,'Datos Titular Substituto');
    $grid->row([['Nombre',svPdfFisicoValor($controles,'sustitutoNombre',$principal,['field_41'])],['Domicilio',svPdfFisicoValor($controles,'sustitutoDomicilio',$principal,['field_42'])],['Edad',svPdfFisicoValor($controles,'sustitutoEdad',$principal,['field_43'])]],[1.25,1.7,.55]);
    $grid->row([['Teléfono',svPdfFisicoValor($controles,'sustitutoTelefono',$principal,['field_44'])],['Parentesco',svPdfFisicoValor($controles,'sustitutoParentesco',$principal,['field_45'])],['I.D.',svPdfFisicoValor($controles,'sustitutoId',$principal,['field_46'])]],[1.2,1,1]);

    if ($operacionPrincipal === 'USO INMEDIATO') {
        svPdfV3Section($grid,'Información de Uso Inmediato');
        $grid->row([['Nombres del finado',svPdfFisicoValor($controles,'finadoNombres',$principal,['field_89'])],['Apellidos del finado',svPdfFisicoValor($controles,'finadoApellidos',$principal,['field_90'])],['Sexo',svPdfFisicoValor($controles,'finadoSexo',$principal,['field_91'])],['Parentesco con titular',svPdfFisicoValor($controles,'finadoParentescoTitular',$principal,['field_94'])]],[1.25,1.25,.6,1.1]);
        $grid->row([['Estatura (m)',svPdfFisicoValor($controles,'finadoEstatura',$principal,['field_92'])],['Peso (kg)',svPdfFisicoValor($controles,'finadoPeso',$principal,['field_93'])],['Causa de defunción',svPdfFisicoValor($controles,'finadoCausaDefuncion',$principal,['field_95'])],['Procedencia',svPdfFisicoValor($controles,'finadoProcedencia',$principal,['field_96'])]],[.7,.7,1.55,1.05]);
        $grid->row([['Nombres del corresponsable',svPdfFisicoValor($controles,'uiCorresponsableNombres',$principal,['field_97'])],['Apellidos del corresponsable',svPdfFisicoValor($controles,'uiCorresponsableApellidos',$principal,['field_98'])],['Parentesco con finado',svPdfFisicoValor($controles,'uiCorresponsableParentesco',$principal,['field_99'])],['Celular del corresponsable',svPdfFisicoValor($controles,'uiCorresponsableCelular',$principal,['field_100'])]],[1.2,1.2,1,1]);
        $obsUi=svPdfFisicoValor($controles,'uiObservaciones',$principal,['field_101']); if($obsUi!=='')$grid->row([['Observaciones de uso inmediato',$obsUi]]);
    }

    svPdfV3Section($grid,'Información de la Venta');
    $grid->row([['Tipo de contrato',svPdfFisicoValor($controles,'tipoContrato',$principal,['field_51'])],['Paquete / Plan',svPdfFisicoValor($controles,'paquete',$principal,['Paquete'])]]);
    $grid->row([['Descripción de la venta',svPdfFisicoValor($controles,'descripcionVenta',$principal,['field_61'])]]);

    svPdfV3Section($grid,'Importe y Forma de Pago');
    $grid->row([['Forma de pago',svPdfFisicoValor($controles,'formaPago',$principal,['field_62'])],['Precio total',svPdfFisicoValor($controles,'precioTotal',$principal,['field_63'],'money')],['Enganche',svPdfFisicoValor($controles,'enganche',$principal,['field_64'],'money')],['Saldo',svPdfFisicoValor($controles,'saldo',$principal,['field_65'],'money')]],[.85,1.05,1,1]);
    $grid->row([['Método de pago',svPdfFisicoValor($controles,'metodoPago',$principal,['field_69'])]]);
    $formaPago=strtoupper(svPdfFisicoValor($controles,'formaPago',$principal,['field_62']));
    if($formaPago==='CREDITO'||$formaPago==='CRÉDITO'){
        $grid->subBand('Financiamiento');
        $grid->row([['Valor total',svPdfFisicoValor($controles,'precioTotal',$principal,['field_70','field_63'],'money')],['Inicial',svPdfFisicoValor($controles,'enganche',$principal,['field_71','field_64'],'money')],['Saldo',svPdfFisicoValor($controles,'saldo',$principal,['field_72','field_65'],'money')]]);
        $grid->row([['Mensualidades',svPdfFisicoValor($controles,'mensualidades',$principal,['field_66','field_73'])],['Importe mensual',svPdfFisicoValor($controles,'importeMensual',$principal,['field_67','field_74'],'money')],['Día de pago',svPdfFisicoValor($controles,'diaPago',$principal,['field_68'])],['Fecha de inicio',svPdfFisicoValor($controles,'fechaPrimerVencimiento',$principal,['field_75'],'date')]],[.9,1.1,.75,1.25]);
        $grid->row([['Precio de lista',svPdfFisicoValor($controles,'precioLista',$principal,['field_76','Precio_Base_Componente'],'money')],['Bonificación',svPdfFisicoValor($controles,'bonificacion',$principal,['field_77'],'money')],['Monto a financiar',svPdfFisicoValor($controles,'montoFinanciar',$principal,['field_78'],'money')],['Interés (%)',svPdfFisicoValor($controles,'interesFinanciamiento',$principal,['field_79'])]],[1.1,.9,1.2,.75]);
        $grid->row([['Periodo de pagos',svPdfFisicoValor($controles,'periodoPagos',$principal,['field_80'])],['Pagos anuales',svPdfFisicoValor($controles,'pagosAnuales',$principal,['field_81'])],['Total a pagar',svPdfFisicoValor($controles,'totalPagar',$principal,['field_82'],'money')],['Conformidad',svPdfFisicoValor($controles,'conformidadFinanciamiento',$principal,['Financiamiento_Conformidad'],'bool')]],[1.1,.9,1.15,.8]);
    }

    svPdfV3Section($grid,'Documentación recibida');
    $pdf->checklist([
        ['Identificación oficial del titular · Frente',svPdfBool($principal['Documento_ID_Titular']??false)],['Identificación oficial del titular · Reverso',svPdfBool($principal['Documento_ID_Titular']??false)],['Identificación del titular substituto · Frente',svPdfBool($principal['Documento_ID_Sustituto']??false)],['Identificación del titular substituto · Reverso',svPdfBool($principal['Documento_ID_Sustituto']??false)],['Comprobante de domicilio',svPdfBool($principal['Documento_Comprobante_Domicilio']??false)],['Comprobante de pago',svPdfBool($principal['Documento_Comprobante_Pago']??false)]
    ]);
    $otros=svPdfFisicoValor($controles,'documentoOtros',$principal,['field_88']); if($otros!=='')$grid->row([['Otros documentos',$otros]]);
    $observaciones=svPdfFisicoValor($controles,'observacionesSolicitud',$principal,['Observaciones_Solicitud']); if($observaciones!==''){svPdfV3Section($grid,'Observaciones de la solicitud');$pdf->note($observaciones);}

    $pdf->beginBackPage();
    $grid=new SvPdfFisicoGrid($pdf);
    if (!$preliminar) {
        svPdfV3Section($grid,'Autorizaciones internas');
        svPdfV3ApprovalBox($pdf,'Vo.Bo. Comercial',strtoupper(svPdfFisicoTexto($principal['VoBo_Estatus']??'APROBADO')),svPdfFisicoTexto($principal['VoBo_Por']??''),svPdfFecha(svPdfFisicoTexto($principal['VoBo_Fecha']??'')),$firmaVoboComercial);
        $cobranzaPor=trim($cobranzaRevisor)!==''?trim($cobranzaRevisor):svPdfFisicoTexto($principal['Cobranza_Por']??'');
        $cobranzaCuando=trim($cobranzaFecha)!==''?trim($cobranzaFecha):svPdfFisicoTexto($principal['Cobranza_Fecha']??'');
        svPdfV3ApprovalBox($pdf,'Vo.Bo. de Cobranza','APROBADO',$cobranzaPor,svPdfFecha($cobranzaCuando),$firmaVoboCobranza);
    }
    svPdfV3Section($grid,'Declaración de conformidad');
    $pdf->note('El cliente manifiesta su conformidad con la información capturada en esta Solicitud de Venta y con las condiciones, importes, componentes y servicios asentados en el expediente digital del folio.');
    svPdfV3Section($grid,'Firmas de conformidad');
    $pdf->signaturePair($firmaCliente,$firmaVendedor,$cliente,$vendedor);
    svPdfV3Section($grid,'Control del documento');
    $grid->row([
        ['Estatus final',$preliminar?'PRELIMINAR - EN REVISION':'APROBADA'],
        ['Fecha de generación',svPdfFecha(gmdate('c'))]
    ]);
    $pdf->note($preliminar
        ? 'DOCUMENTO PRELIMINAR NO OFICIAL. Se genera al enviar la solicitud a revision y no sustituye la Solicitud de Venta aprobada. Los Vo.Bo. Comercial y de Cobranza se incorporan unicamente en el documento final.'
        : 'Documento final generado por el Portal Interno de Jardines de Juan Pablo. La documentación y las evidencias originales permanecen en el expediente electrónico asociado al folio.'
    );

    if ($preliminar) svPdfV3AplicarMarcaPreliminar($pdf);

    return [
        'contenido'=>$pdf->build(),
        'nombre'=>($preliminar?'SOLICITUD_PRELIMINAR_':'SOLICITUD_FINAL_').$folio.'.pdf',
        'cliente'=>$cliente,
        'tipoVenta'=>$tipoVenta
    ];
}

function svPdfGenerarYGuardarFisicoV3(string $folio,array $grupo,string $graphToken,array $config,string $cobranzaRevisor='',string $cobranzaFecha=''): array
{
    $driveId=svPdfDriveExpedientes($graphToken,(string)$config['siteId']);
    svPdfAsegurarCarpeta($graphToken,$driveId,$folio);
    $estado=svPdfFisicoCargarEstado($graphToken,$driveId,$folio);
    $firmaCliente=svPdfDescargarFirma($graphToken,$driveId,$folio,'FIRMA_CLIENTE');
    $firmaVendedor=svPdfDescargarFirma($graphToken,$driveId,$folio,'FIRMA_VENDEDOR');
    $firmaVoboComercial=svPdfDescargarFirma($graphToken,$driveId,$folio,'FIRMA_VOBO_COMERCIAL');
    $firmaVoboCobranza=svPdfDescargarFirma($graphToken,$driveId,$folio,'FIRMA_VOBO_COBRANZA');
    $documento=svPdfConstruirFinalFisicoV3($folio,$grupo,$estado,$cobranzaRevisor,$cobranzaFecha,$firmaCliente,$firmaVendedor,$firmaVoboComercial,$firmaVoboCobranza);
    $item=svPdfSubir($graphToken,$driveId,$folio,$documento['nombre'],$documento['contenido']);
    return ['nombre'=>$documento['nombre'],'contenido'=>$documento['contenido'],'cliente'=>$documento['cliente'],'tipoVenta'=>$documento['tipoVenta'],'driveItemId'=>(string)($item['id']??''),'webUrl'=>(string)($item['webUrl']??''),'firmaClienteIncluida'=>is_string($firmaCliente)&&$firmaCliente!=='','firmaVendedorIncluida'=>is_string($firmaVendedor)&&$firmaVendedor!=='','firmaVoboComercialIncluida'=>is_string($firmaVoboComercial)&&$firmaVoboComercial!=='','firmaVoboCobranzaIncluida'=>is_string($firmaVoboCobranza)&&$firmaVoboCobranza!=='','layout'=>'fisico-v3'];
}

function svPdfGenerarYGuardarPreliminarFisicoV3(string $folio,array $grupo,string $graphToken,array $config): array
{
    $driveId=svPdfDriveExpedientes($graphToken,(string)$config['siteId']);
    svPdfAsegurarCarpeta($graphToken,$driveId,$folio);
    $estado=svPdfFisicoCargarEstado($graphToken,$driveId,$folio);
    $firmaCliente=svPdfDescargarFirma($graphToken,$driveId,$folio,'FIRMA_CLIENTE');
    $firmaVendedor=svPdfDescargarFirma($graphToken,$driveId,$folio,'FIRMA_VENDEDOR');
    $documento=svPdfConstruirFinalFisicoV3($folio,$grupo,$estado,'','',$firmaCliente,$firmaVendedor,null,null,true);
    $item=svPdfSubir($graphToken,$driveId,$folio,$documento['nombre'],$documento['contenido']);
    return [
        'nombre'=>$documento['nombre'],
        'contenido'=>$documento['contenido'],
        'cliente'=>$documento['cliente'],
        'tipoVenta'=>$documento['tipoVenta'],
        'driveItemId'=>(string)($item['id']??''),
        'webUrl'=>(string)($item['webUrl']??''),
        'firmaClienteIncluida'=>is_string($firmaCliente)&&$firmaCliente!=='',
        'firmaVendedorIncluida'=>is_string($firmaVendedor)&&$firmaVendedor!=='',
        'firmaVoboComercialIncluida'=>false,
        'firmaVoboCobranzaIncluida'=>false,
        'layout'=>'fisico-v3-preliminar'
    ];
}
