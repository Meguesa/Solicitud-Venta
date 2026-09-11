# Solicitud de Venta JJP

Herramienta independiente para digitalizar y administrar el proceso de Solicitud de Venta de Jardines de Juan Pablo.

## Produccion

- Portal: `https://portal.juanpablo.com.mx/solicitud-venta/`
- Inicio / Mis solicitudes: `/solicitud-venta/inicio/`
- Vo.Bo. Comercial y Cobranza: `/solicitud-venta/vobo/`
- Firma remota publica: `/firma/`
- Backend: `/api/solicitud-venta/`

`Meguesa/Solicitud-Venta` es la fuente oficial del frontend, backend, firma remota, PDFs, notificaciones, permisos funcionales y despliegue de Solicitud de Venta.

El Portal Interno solamente proporciona la sesion SSO compartida de Microsoft 365 y la navegacion general. La logica funcional de Solicitud de Venta no debe implementarse en `Portal-Interno-JJP`.

## Estructura

```text
Solicitud-Venta/
├── .github/workflows/      # Despliegue canonico y disparador automatico
├── api/solicitud-venta/    # Backend PHP
├── docs/                   # Documentacion tecnica
├── firma/                  # Experiencia publica de firma remota
├── inicio/                 # Pantalla Mis solicitudes
├── src/
│   ├── js/                 # Modulos JavaScript de captura y flujo
│   └── css/                # Estilos de la interfaz autenticada
├── tools/                  # Build y despliegue FTPS
├── vobo/                   # Bandejas de Vo.Bo. Comercial y Cobranza
├── index.php               # Entrada autenticada de produccion
├── index.html              # Plantilla base de captura
└── README.md
```

Los archivos fuente JS/CSS viven bajo `src/`. El builder los copia al nivel raiz del paquete de produccion para conservar las URLs existentes de `/solicitud-venta/`; por lo tanto, esta organizacion no cambia las rutas publicas ni obliga a modificar enlaces almacenados.

Los modulos de produccion ya no utilizan nombres temporales `*-fix.js`. Los nombres funcionales actuales incluyen `correccion.js`, `correccion-validacion.js`, `documentacion.js` y `firma-remota-preflight.js`.

## Componentes principales

### Captura y persistencia

- Creacion y actualizacion de borradores en SharePoint.
- Componentes multiples por solicitud.
- Integracion de corrida de financiamiento.
- Documentacion e identificaciones.

### Firmas

- Firma presencial de cliente y vendedor.
- Firma remota mediante enlace seguro.
- Evidencia de consentimiento de privacidad.
- Seguimiento y gestion del estado de firma remota.

### Vo.Bo.

- Vo.Bo. Comercial.
- Vo.Bo. de Cobranza.
- Solicitud de correcciones.
- Registro de firmas y responsables de autorizacion.

### Documentos

- PDF preliminar durante revision.
- PDF final una vez autorizado.
- Expediente electronico asociado al folio.

### Notificaciones

Los destinatarios se resuelven desde grupos de SharePoint, no mediante correos hardcodeados en el frontend.

## Autorizacion

Los roles funcionales viven en `api/solicitud-venta/autorizacion.php` y se resuelven mediante grupos de SharePoint:

- `Solicitud Venta - Vendedores`
- `Solicitud Venta - Coordinadores`
- `Solicitud Venta - Gerencia Comercial`
- `Solicitud Venta - Asistente Ventas`
- `Solicitud Venta - Cobranza`
- `Solicitud Venta - Direccion`
- `Solicitud Venta - Administradores`

Los grupos de notificacion y expediente final se administran por separado.

## Build y despliegue

Workflow canonico:

`.github/workflows/publicar-solicitud-cpanel.yml`

La construccion de produccion esta centralizada en:

`tools/build_solicitud_package.py`

Este script crea `_deploy/`, copia el codigo fuente a las rutas publicas existentes, valida marcadores criticos y deja listo el paquete para publicacion. No modifica la logica JavaScript ni PHP durante el build. La autenticacion compartida del Portal, la recuperacion de borradores y la firma remota viven directamente en el codigo fuente; no existe una capa de normalizacion de runtime.

El workflow valida la estructura, ejecuta el builder, valida PHP y publica por FTPS mediante `tools/deploy_solicitud_ftps.sh`.

El despliegue solo puede escribir en:

- `/solicitud-venta/`
- `/api/solicitud-venta/`
- `/firma/`

No debe publicar ni modificar Dashboard, Mapa del Panteon, Financiamiento independiente ni archivos propios del Portal.

## Configuracion privada

Las credenciales, secretos e IDs de Microsoft/SharePoint permanecen fuera del repositorio, en la configuracion privada del servidor. No deben agregarse secretos al frontend ni al control de versiones.

## Documentacion

- [`docs/FORMULARIO_FISICO_MAPEO.md`](docs/FORMULARIO_FISICO_MAPEO.md): correspondencia entre el formulario fisico y la captura digital.

## Regla de mantenimiento

Antes de eliminar, mover o consolidar un archivo del runtime:

1. verificar referencias en `index.php`, `index.html` y `src/js/`;
2. verificar `tools/build_solicitud_package.py` y `tools/deploy_solicitud_ftps.sh`;
3. construir y validar `_deploy/`;
4. probar captura, guardado/reanudacion, firma presencial/remota, Vo.Bo., correcciones, PDF y notificaciones;
5. mantener toda la logica funcional en el codigo fuente y evitar parches de runtime durante el despliegue.
