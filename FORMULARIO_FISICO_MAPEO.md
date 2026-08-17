# Mapeo de Solicitud de Venta fisica a formulario digital

Objetivo: que la experiencia del vendedor conserve la misma secuencia y apartados de la solicitud fisica actual. Los datos adicionales requeridos por ProcaP, Cobranza o automatizaciones posteriores no deben interrumpir la captura principal; se muestran solo cuando aplican o se incorporan en etapas posteriores del flujo.

## Frente de la solicitud

1. Lugar y fecha
2. Referencia
3. Datos del titular
4. Datos de contacto y domicilio
5. Datos laborales / economicos del titular
6. Titular sustituto o datos del fallecido, segun el tipo de operacion
7. Producto / servicio contratado
   - tipo de servicio o propiedad
   - tipo de ataud / urna cuando aplique
   - duracion del servicio cuando aplique
8. Importe y forma de pago
9. Firma / conformidad del titular
10. Nombre y firma del vendedor
11. Referencia / observaciones finales

## Reverso - Financiamiento

Seccion separada, visible solo cuando la forma de pago sea CREDITO:

- Valor total
- Inicial
- Saldo
- Numero de mensualidades
- Importe de mensualidad
- Fecha de inicio / primer vencimiento
- Dia de pago
- Conformidad del cliente

## Regla de interfaz

La pantalla del vendedor debe usar estos mismos apartados y en este mismo orden. Los campos de control interno de ProcaP, Vo.Bo., Cobranza, contrato y Direccion se manejaran fuera de la captura principal del vendedor o como informacion contextual no invasiva.
