'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');

const source = fs.readFileSync('src/js/app.js', 'utf8');
const start = source.indexOf('let guardadoBorradorEnCurso = false;');
const end = source.indexOf('async function refrescarRevisionBorrador(', start);
assert.ok(start >= 0 && end > start, 'Localizar funcion de guardado real');
const code = source.slice(start, end);
const calls = [];
let resolveFirst = null;
let responseNumber = 0;
let logged = [];
const draft = {itemId: '123', folio: 'SV-2026-000123', revision: '"v1"'};
const fakeDoc = {
  getElementById(id) { return id === 'btnSaveDraft' ? {disabled:false} : null; }
};
const win = {solicitudVentaAuth: {getBackendAccessToken: async()=> 'TEST_SESSION'}};
const payload = () => ({
  accion:'guardar_borrador', itemId:draft.itemId, folio:draft.folio,
  revision:draft.revision, tipoSolicitud:'SERVICIO', tipoOperacion:'PREVISION',
  tipoVentaProcap:'SERVICIO PREVISION', fechaSolicitud:'2026-11-09'
});
async function fakeFetch(_url, init){
  calls.push(JSON.parse(init.body));
  responseNumber++;
  if(responseNumber===1){
    return new Promise(resolve=>{resolveFirst = ()=>resolve({
      ok:true, status:200, json: async()=>({ok:true,itemId:'123',folio:draft.folio,revision:'"v2"'})
    });});
  }
  return {ok:true,status:200,json:async()=>({ok:true,itemId:'123',folio:draft.folio,revision:'"v3"'})};
}
const engine = new Function('document','window','borradorActual','construirPayloadBorrador',
  'mostrarMensaje','actualizarFolio','fetch', code+'\nreturn guardarBorrador;');
const save = engine(fakeDoc,win,draft,payload,(msg,type)=>logged.push({msg,type}),()=>{},fakeFetch);
async function main(){
  const one=save();
  // Dejar avanzar la primera llamada hasta el fetch pendiente.
  for(let i=0;i<10&&!resolveFirst;i++) await Promise.resolve();
  assert.ok(resolveFirst, 'Se inició la primera petición');
  const two=await save();
  assert.equal(two,false,'Se evita segundo guardado simultáneo');
  assert.equal(calls.length,1,'No hay doble petición');
  resolveFirst();
  assert.equal(await one,true,'Primer guardado confirmado');
  assert.equal(draft.revision,'"v2"','Se guarda la revision devuelta');
  assert.equal(await save(),true,'Segundo guardado después del primero');
  assert.equal(calls.length,2,'Solo dos solicitudes secuenciales');
  assert.equal(calls[0].revision,'"v1"');
  assert.equal(calls[1].revision,'"v2"');
  console.log('PASS: guardado serializado, version enviada, version renovada y segunda captura permitida.');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
