<?php
declare(strict_types=1);
// Escenarios de autorización: ejecutar las funciones reales, sin SharePoint ni usuarios reales.
$source = file_get_contents(__DIR__ . '/../../api/solicitud-venta/autorizacion.php');
$start = strpos($source, 'function svSolicitudTieneRol(');
$end = strpos($source, 'function svSolicitudRequireVobo()', $start);
if (!is_string($source) || $start === false || $end === false || $end <= $start) {
    throw new RuntimeException('No fue posible aislar las reglas reales de roles');
}
eval(substr($source, $start, $end - $start));
$passed=0; $failed=0; $issues=[];
$cases = [
  ['VENDEDOR',['VENDEDOR'],false,false],
  ['COORDINADOR',['COORDINADOR'],true,false],
  ['GERENCIA_COMERCIAL',['GERENCIA_COMERCIAL'],true,false],
  ['ASISTENTE_VENTAS',['ASISTENTE_VENTAS'],false,false],
  ['COBRANZA',['COBRANZA'],false,true],
  ['DIRECCION',['DIRECCION'],false,false],
  ['ADMINISTRADOR',['ADMINISTRADOR'],true,true],
  ['SIN_ROLES',[],false,false],
  ['COMERCIAL_Y_COBRANZA',['GERENCIA_COMERCIAL','COBRANZA'],true,true],
];
foreach ($cases as [$name,$roles,$commercial,$collection]) {
    $ctx=['resolved'=>true,'roles'=>$roles];
    foreach (['comercial'=>[$commercial,svSolicitudCanVobo($ctx)],
              'cobranza'=>[$collection,svSolicitudCanCobranzaVobo($ctx)]] as $stage=>$pair) {
        [$expected,$actual]=$pair;
        if ($actual === $expected) {
            ++$passed; echo "PASS | $name | $stage\n";
        } else {
            ++$failed;
            $issues[] = "$name / $stage: esperado ".json_encode($expected).", obtenido ".json_encode($actual);
            echo "FAIL | ".end($issues)."\n";
        }
    }
}
$ctx=['resolved'=>false,'roles'=>['ADMINISTRADOR','COBRANZA','GERENCIA_COMERCIAL']];
foreach (['comercial'=>svSolicitudCanVobo($ctx),'cobranza'=>svSolicitudCanCobranzaVobo($ctx)] as $stage=>$actual) {
    if ($actual===false) {$passed++;echo "PASS | contexto no resuelto | $stage\n";}
    else {$failed++; $issues[]="Autorizacion concedida con contexto no resuelto: $stage";}
}
$built=[
  'tipo'=>'AUTORIZACION_FUNCIONAL',
  'aprobadas'=>$passed,
  'fallidas'=>$failed,
  'fallos'=>$issues,
  'limitaciones'=>'Simula el contexto de roles con las funciones reales; no verifica membresias ni sesiones reales de Microsoft 365'
];
@mkdir('qa-auditoria-resultados',0775,true);
file_put_contents('qa-auditoria-resultados/permisos.json',json_encode($built,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo 'RESUMEN_PERMISOS '.json_encode($built)."\n";
exit($failed?1:0);
