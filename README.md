# Solicitud de Venta JJP

Herramienta independiente para digitalizar y administrar el proceso de Solicitud de Venta de Jardines de Juan Pablo.

## Produccion

- Portal: `https://portal.juanpablo.com.mx/solicitud-venta/`
- Inicio / Mis solicitudes: `/solicitud-venta/inicio/`
- Vo.Bo. Comercial y Cobranza: `/solicitud-venta/vobo/`
- Firma remota publica: `/firma/`
- Backend: `/api/solicitud-venta/`

El repositorio `Meguesa/Solicitud-Venta` es la fuente oficial de frontend, backend, firma remota, PDFs, notificaciones, permisos funcionales y despliegue de Solicitud de Venta.

El Portal Interno solamente proporciona la sesion SSO compartida de Microsoft 365 y la navegacion general. La logica funcional de Solicitud de Venta no debe implementarse en `Portal-Interno-JJP`.

## Estructura

```text
Solicitud-Venta/
├── .github/workflows/      # Despliegues de GitHub Actions
├── api/solicitud-venta/    # Backend PHP
├── docs/                   # Documentacion tecnica
├── firma/                  # Experiencia publica de firma remota
├── inicio/                 # Pantalla Mis solicitudes
├── tools/                  # Scripts de construccion y despliegue
├── vobo/                   # Bandejas de Vo.Bo. Comercial y Cobranza
├── index.php               # Entrada autenticada de produccion
├── index.html              # Plantilla base de la captura
├── app.js                  # Logica principal de captura
├── auth.js                 # Adaptador de sesion SSO del Portal
├── persistencia.js         # Persistencia de borradores
├── wizard.js               # Navegacion por pasos y resumen
└── styles.css              # Estilos principales
```

Los archivos JavaScript adicionales de la raiz son modulos funcionales cargados por el proceso de produccion. Aunque algunos conservan sufijos historicos como `-fix`, actualmente forman parte del runtime y no deben eliminarse ni moverse sin refactorizar primero sus referencias en el workflow canonico.

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

## Despliegue

Workflow canonico:

`.github/workflows/publicar-solicitud-cpanel.yml`

Este workflow construye un paquete independiente y publica exclusivamente:

- `/solicitud-venta/`
- `/api/solicitud-venta/`
- `/firma/`

No debe publicar ni modificar Dashboard, Mapa del Panteon, Financiamiento independiente ni archivos propios del Portal.

## Configuracion privada

Las credenciales, secretos y IDs de Microsoft/SharePoint permanecen fuera del repositorio, en la configuracion privada del servidor. No deben agregarse secretos al frontend ni al control de versiones.

## Documentacion

- [`docs/FORMULARIO_FISICO_MAPEO.md`](docs/FORMULARIO_FISICO_MAPEO.md): correspondencia entre el formulario fisico y la captura digital.

## Regla de mantenimiento

Antes de eliminar o mover un archivo del runtime:

1. verificar referencias en `index.php`, `index.html` y los modulos JS;
2. verificar referencias en `.github/workflows/publicar-solicitud-cpanel.yml`;
3. construir y validar el paquete de produccion;
4. probar captura, firma, Vo.Bo., PDF y notificaciones antes de considerar terminado el cambio.
