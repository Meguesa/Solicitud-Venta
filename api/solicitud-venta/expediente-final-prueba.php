<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!portal_is_authenticated()) {
    http_response_code(401);
    exit('Sesion requerida.');
}
if (!portal_user_can_cobranza_vobo() && !portal_user_can_vobo()) {
    http_response_code(403);
    exit('Sin autorizacion para esta prueba.');
}

$folio = strtoupper(trim((string) ($_GET['folio'] ?? 'SV-2026-000027')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    $folio = 'SV-2026-000027';
}
?><!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prueba expediente final | Solicitud de Venta</title>
<style>
body{margin:0;background:#f5f1ec;font-family:Arial,sans-serif;color:#2b1b15}.wrap{max-width:860px;margin:48px auto;padding:0 20px}.card{background:#fff;border:1px solid #e4d9cf;border-radius:16px;padding:28px;box-shadow:0 12px 30px rgba(0,0,0,.05)}h1{color:#225b8a;margin-top:0}.meta{padding:14px 0;border-bottom:1px solid #eee7e1}.meta strong{display:block;font-size:12px;color:#6b625d;text-transform:uppercase}.btn{margin-top:22px;background:#225b8a;color:#fff;border:0;border-radius:9px;padding:13px 18px;font-weight:700;cursor:pointer}.btn:disabled{opacity:.6;cursor:wait}.result{margin-top:20px;padding:14px;border-radius:10px;white-space:pre-wrap;word-break:break-word}.ok{background:#e9f7ef;color:#126b3b}.err{background:#fff0e8;color:#9a3d00}.info{background:#eef5fb;color:#225b8a}a{color:#225b8a;font-weight:700;text-decoration:none}
</style>
</head>
<body><main class="wrap"><section class="card">
<h1>Prueba de expediente final</h1>
<p>Esta prueba <strong>no cambia el estatus</strong> de la solicitud. Solo intenta generar/confirmar el PDF final y enviar el expediente por correo.</p>
<div class="meta"><strong>Folio</strong><?= htmlspecialchars($folio, ENT_QUOTES, 'UTF-8') ?></div>
<button id="btn" class="btn" type="button">Reintentar envio del expediente</button>
<div id="result" class="result info" hidden></div>
<p style="margin-top:22px"><a href="/solicitud-venta/inicio/">Volver a Mis Solicitudes</a></p>
</section></main>
<script>
(() => {
  const btn=document.getElementById('btn');
  const result=document.getElementById('result');
  const folio=<?= json_encode($folio, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  btn.addEventListener('click', async()=>{
    btn.disabled=true;
    result.hidden=false;
    result.className='result info';
    result.textContent='Procesando expediente final... Esto puede tardar mientras se generan y adjuntan los archivos.';
    try{
      const response=await fetch('/api/solicitud-venta/notificar-expediente-final.php',{method:'POST',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({folio})});
      const text=await response.text();
      let data=null;
      try{data=JSON.parse(text);}catch(_){data=null;}
      if(!response.ok||!data?.ok){
        result.className='result err';
        result.textContent=data?.message||data?.error||`HTTP ${response.status}\n${text}`;
        return;
      }
      result.className='result ok';
      const recipients=Array.isArray(data.destinatarios)?data.destinatarios.join(', '):'';
      result.textContent=(data.message||'Expediente enviado correctamente.')
        +(recipients?`\nDestinatarios: ${recipients}`:'')
        +(data.partes?`\nCorreos enviados: ${data.partes}`:'')
        +(data.archivos?`\nArchivos adjuntos: ${data.archivos}`:'');
    }catch(error){
      result.className='result err';
      result.textContent=error?.message||String(error);
    }finally{btn.disabled=false;}
  });
})();
</script></body></html>
