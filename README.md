# Solicitud de Venta JJP

Herramienta independiente para digitalizar el proceso de Solicitud de Venta de Jardines de Juan Pablo.

## Objetivo inicial

- Autenticacion con Microsoft Entra ID.
- Mantener la captura del vendedor lo mas similar posible a la solicitud fisica actual.
- Integrar posteriormente el flujo de Vo.Bo., ProcaP, Cobranza, contratos y firmas.
- Preparar la aplicacion para integrarse posteriormente al Portal Interno JJP bajo `/solicitud-venta/`.

## Configuracion Entra

- Aplicacion: `Solicitud Venta JJP`
- Redirect URI de produccion: `https://portal.juanpablo.com.mx/solicitud-venta/`

No se almacenan secretos en el frontend.
