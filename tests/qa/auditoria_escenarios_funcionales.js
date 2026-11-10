'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const app = fs.readFileSync('src/js/app.js', 'utf8');
const config = fs.readFileSync('src/js/config.js', 'utf8');
let ok = 0, fail = 0;
const failed = [];
function check(nombre, run) {
  try { run(); ok++; console.log('PASS | ' + nombre); }
  catch (e) { fail++; failed.push({escenario:nombre,error:String(e.message || e)}); console.log('FAIL | ' + nombre + ' | ' + (e.message || e)); }
}
function expect(cond,msg) { if(!cond) throw Error(msg); }
function sliceFunction(source, from, to) {
  const start=source.indexOf(from),end=source.indexOf(to,start+from.length);
  expect(start>=0 && end>start, 'No se encuentra la funcion real: ' + from);
  return source.slice(start,end);
}

// Combinar las opciones de SERVICIO / LOTE / NICHO con PREVISION / USO INMEDIATO.
// Se ejecuta el codigo de negocio real y se consulta el resultado de ProcaP.
const typeScript=sliceFunction(app,'function actualizarFormularioDinamico() {','  const esServicio');
for(const [tipo,operacion,esperado] of [
  ['SERVICIO','PREVISION','SERVICIO PREVISION'],
  ['SERVICIO','USO INMEDIATO','SERVICIO UI'],
  ['LOTE','PREVISION','CEMENTERIO PREVISION'],
  ['LOTE','USO INMEDIATO','CEMENTERIO UI'],
  ['NICHO','PREVISION','NICHO PREVISION'],
  ['NICHO','USO INMEDIATO','NICHO UI'],
]) {
  check('Tipo ProcaP: '+tipo+' / '+operacion,()=>{
    const controls={tipoSolicitud:{value:tipo},tipoOperacion:{value:operacion},tipoVentaProcap:{value:''},tipoContrato:{value:''},propiedadTipo:{},tituloSolicitud:{},ventaDescripcion:{}};
    const document={getElementById(id){return controls[id]||null;}};
    const fn=vm.runInNewContext(typeScript+'\n}\nactualizarFormularioDinamico;', {document});
    fn();
    expect(controls.tipoVentaProcap.value===esperado, 'Obtenido: '+controls.tipoVentaProcap.value+', esperado: '+esperado);
    expect(controls.tipoContrato.value===esperado,'Contrato y ProcaP desincronizados');
  });
}

// RFC, CURP, numero ID con o sin tipo; sin identificadores debe rechazar.
const identifyScript=sliceFunction(app,'function validarIdentificacionCliente()','window.validarIdentificacionCliente =');
const identifyCases=[
 ['solo RFC',{rfc:'GUGA850101AB0'},true],
 ['solo CURP',{curp:'GUGA850101HNLRRB09'},true],
 ['solo numero y tipo',{numero:'ABC123',tipo:'INE'},true],
 ['numero sin tipo',{numero:'ABC123'},false],
 ['sin identificadores',{},false],
 ['RFC y CURP',{rfc:'RFC123',curp:'CURP123'},true],
 ['tipo sin numero', {tipo:'INE'},false],
 ['numero, RFC y CURP',{numero:'123',tipo:'INE',rfc:'RFC',curp:'CURP'},true],
];
for(const [name,c,expected] of identifyCases)check('Identificacion: '+name,()=>{
 const fields={};
 for(const [id,val] of Object.entries({clienteNumeroId:c.numero||'',clienteRfc:c.rfc||'',clienteCurp:c.curp||'',clienteTipoId:c.tipo||''})){
   fields[id]={value:val,validationMessage:'',setCustomValidity(s){this.validationMessage=s;},setAttribute(){},removeAttribute(){}};
 }
 const document={getElementById(id){return fields[id]}};
 const fn=vm.runInNewContext(identifyScript+'\nvalidarIdentificacionCliente;', {document});
 expect(fn()===expected,'Validacion incorrecta');
});

// Calculos de la funcion real que usa el formulario para saldo y mensualidad.
const moneyScript=sliceFunction(app,'function recalcularImportes()','function actualizarFolio(');
for(const [name,data,expected] of [
 ['sin enganche',{precioTotal:10000,enganche:0,mensualidades:10,precioLista:10000},['10000.00','1000.00']],
 ['enganche parcial',{precioTotal:10000,enganche:2000,mensualidades:8,precioLista:10000},['8000.00','1000.00']],
 ['liquidacion completa',{precioTotal:10000,enganche:10000,mensualidades:12,precioLista:10000},['0.00','0.00']],
 ['sobrepago',{precioTotal:10000,enganche:15000,mensualidades:12,precioLista:10000},['0.00','0.00']],
 ['cero mensualidades',{precioTotal:10000,enganche:2000,mensualidades:0,precioLista:10000},['8000.00','0.00']],
 ['credito con interes',{precioTotal:10000,enganche:2000,mensualidades:8,precioLista:10000,interesFinanciamiento:10},['8000.00','1000.00']],
 ['bonificacion',{precioTotal:10000,enganche:2000,mensualidades:8,precioLista:10000,bonificacion:1000},['8000.00','1000.00']],
]){
 check('Calculo importe: '+name,()=>{
   const fields={};
   for(const id of ['precioTotal','enganche','mensualidades','saldo','importeMensual','precioLista','bonificacion','montoFinanciar','interesFinanciamiento','totalPagar'])fields[id]={value:String(data[id]||'')};
   const document={getElementById(id){return fields[id]||null;}};
   const fn=vm.runInNewContext(moneyScript+'\nrecalcularImportes;', {document});
   fn();
   expect(fields.saldo.value===expected[0],'Saldo '+fields.saldo.value);
   expect(fields.importeMensual.value===expected[1],'Mensualidad '+fields.importeMensual.value);
   if(name==='credito con interes')expect(fields.totalPagar.value==='8800.00','Total con interes incorrecto');
   if(name==='bonificacion')expect(fields.montoFinanciar.value==='7000.00','Monto con bonificacion incorrecto');
 });
}

// Ejecuta la regla universal real de config.js contra un DOM simulado.
// Una venta UI NO debe exigir sustituto ni mostrarlo como obligatorio.
const start=config.indexOf('// Titular Substituto es obligatorio');
const end=config.indexOf('  // El workflow del Portal',start);
check('Se puede localizar regla real de titular sustituto',()=>expect(start>=0 && end>start,'No se encontro el bloque'));
for(const operacion of ['PREVISION','USO INMEDIATO']){
 check('Titular sustituto SERVICIO / '+operacion,()=>{
   const controls=[{required:false},{required:false},{required:false}];
   const section={hidden:true,querySelectorAll(){return controls;}};
   const tipo={value:'SERVICIO',addEventListener(){}};
   const op={value:operacion,addEventListener(){}};
   const doc={addEventListener(event,fn){if(event==='DOMContentLoaded')fn();},getElementById(id){return id==='sustitutoSection'?section:id==='tipoSolicitud'?tipo:id==='tipoOperacion'?op:null;}};
   const context={document:doc,setTimeout(fn){fn();}};
   vm.runInNewContext(config.slice(start,end)+'\n});', context);
   if(operacion==='USO INMEDIATO'){
      expect(section.hidden || controls.every(x=>!x.required),'La regla obliga a capturar sustituto para uso inmediato');
   }else{
      expect(!section.hidden && controls.every(x=>x.required),'En prevision no se habilito sustituto');
   }
 });
}

// Aislamiento y protecciones de la copia publicable QA.
const path='tools/build_solicitud_qa.py';
const builder=fs.readFileSync(path,'utf8');
for(const [name,present] of [
 ['Guardia de acceso de Sistemas',builder.includes('svQaAllowedEmail')],
 ['Configuracion privada QA',builder.includes('svQaLoadConfig')],
 ['Correo deshabilitado',builder.includes('QA_EMAIL_DISABLED')],
 ['Bandejas restringidas',builder.includes('Acceso QA exclusivo')],
 ['Bloqueo expediente final',builder.includes('QA no final email')],
 ['Aislamiento de localStorage',builder.includes('solicitudVentaQA:')],
 ['Destinos separados QA',builder.includes('QA_RUNTIME_ROUTE_PRODUCTION')],
]){
  check('Proteccion QA: '+name,()=>expect(present,'No esta presente en el empaquetado'));
}
const output={tipo:'AUDITORIA_FUNCIONAL_CODIGO',fecha:new Date().toISOString(),aprobadas:ok,fallidas:fail,fallos:failed,alcance:'JS frontend real y barreras empaquetado QA; no sustituye navegacion autenticada'};
fs.mkdirSync('qa-auditoria-resultados',{recursive:true});
fs.writeFileSync('qa-auditoria-resultados/formulario.json',JSON.stringify(output,null,2));
console.log('RESUMEN_FORMULARIO '+JSON.stringify(output));
process.exitCode=fail?1:0;
