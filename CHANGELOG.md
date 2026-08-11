# Registro de cambios

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado semántico.

## [No publicado]

### 0.1.0 — en desarrollo

Primera versión funcional. **Todavía no apta para producción**: el módulo no se
ha ejecutado contra una instancia real de Dolibarr; sólo está verificado que
compila en PHP 8.1–8.3, que cumple PSR-12 y que pasa las guardas del proyecto.

**Añadido**

- Timbrado de facturas (Ingreso) y notas de crédito (Egreso).
- Complemento de pago (REP), con parcialidades y saldos calculados del historial
  real de pagos.
- Cancelación con motivos 01–04 y descarga del acuse.
- Consulta de estatus ante el SAT, bajo demanda.
- Descarga del XML y el PDF timbrados al directorio de documentos de la factura.
- CFDI relacionados y factura global.
- Dos ambientes (pruebas / producción) configurados a la vez, con comprobación
  de que la llave corresponda al ambiente elegido.
- Sincronización de clientes y productos con Facty, idempotente.
- Catálogos del SAT leídos de Facty con caché local.
- Listas de facturas y complementos, pestaña por cliente y tablero.
- Cola de reconciliación: un timbrado de resultado desconocido se resuelve
  preguntando a Facty, nunca reintentando a ciegas.
- Pantalla de "qué falta" con CSV de ida y vuelta.

**Decisiones que conviene conocer**

- El PDF lo genera Facty; el módulo lo descarga. No se reimplementa.
- El timbrado automático al validar existe pero viene apagado.
- El cron no consulta el estatus del SAT ni reintenta cancelaciones: ambas cosas
  gastarían timbres o folios sin que nadie lo pidiera.
