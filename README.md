# factymx — módulo de Facty para Dolibarr

Timbra CFDI 4.0 ante el SAT desde Dolibarr, usando [Facty](https://facty.mx) como
plataforma de timbrado.

**Facty es la fuente de verdad fiscal.** Este módulo no construye XML, no guarda
el certificado (CSD), no guarda la contraseña del PAC y no calcula la cadena
original. Manda la intención de negocio como JSON sobre HTTPS y Facty valida,
timbra, almacena y devuelve los artefactos.

## Alcance de la v1

Incluye: facturas (Ingreso), notas de crédito (Egreso), complementos de pago
(REP), cancelación, consulta de estatus SAT, XML y PDF, factura global y CFDI
relacionados.

No incluye todavía: **Complemento Carta Porte** y **Comercio Exterior**. Se dice
aquí y no en letras chicas — si tu operación los necesita, este módulo aún no te
sirve para eso.

## Dos ambientes

Se configuran los dos a la vez y se alterna con un switch:

| Ambiente | Host | Para qué |
|---|---|---|
| Pruebas | `preview.facty.mx` | Comprobantes **sin validez fiscal** |
| Producción | `facty.mx` | CFDI reales ante el SAT |

Son organizaciones distintas en Facty, con bases de datos distintas, así que cada
ambiente necesita **su propia API key**. Al probar la conexión el módulo verifica
que el modo de timbrado que reporta el host corresponda al ambiente elegido, y se
niega a guardar una llave de producción en el campo de pruebas: emitir CFDI
reales creyendo que se está probando es el error más caro que permite esta
funcionalidad.

Mientras el ambiente activo sea pruebas, todas las pantallas del módulo llevan
una banda visible y los documentos quedan marcados con el ambiente en el que se
timbraron.

## Requisitos

- Dolibarr **18.0 o superior** (no hay compatibilidad con versiones anteriores)
- PHP **8.1+** con `curl`
- Salida HTTPS hacia Facty (se respeta el proxy configurado en Dolibarr)
- Una cuenta de Facty con CSD vigente y saldo de timbres

## Instalación

1. Descarga el ZIP de la versión.
2. Dolibarr → Inicio → Configuración → Módulos → **Desplegar módulo externo**.
3. Activa **Facty** en la lista de módulos.
4. Entra a la configuración del módulo, pega tu API key de cada ambiente y usa
   **Probar conexión**.

La llave se crea en Facty → Configuración → API Keys. Se guarda cifrada y no se
vuelve a mostrar completa.

## Generar el ZIP desde el código

```bash
./build/make-zip.sh          # deja dist/factymx-<versión>.zip
```

El script comprueba la sintaxis de todo el árbol antes de empaquetar: publicar un
ZIP con un error de sintaxis es peor que no publicarlo, porque el cliente lo
instala y se le rompe Dolibarr.

## Documentación

- [COMPATIBILIDAD.md](COMPATIBILIDAD.md) — versiones admitidas, qué hace y qué no,
  convivencia con otro módulo de facturación, soporte y seguridad.
- [CHANGELOG.md](CHANGELOG.md) — cambios por versión.

## Estado

**Funcionalmente completo para la v1, y todavía NO apto para producción.**

Están hechas las sub-fases B a K: configuración de los dos ambientes, datos
maestros, timbrado de Ingreso y Egreso, cancelación, estatus SAT, descarga de
artefactos, complemento de pago, CFDI relacionados, factura global, listas,
reconciliación y empaquetado.

Lo que falta es lo que ningún commit puede sustituir: **este código nunca se ha
ejecutado contra una instancia real de Dolibarr.** Lo verificado es que compila
en PHP 8.1–8.3, que cumple PSR-12 y que pasa las guardas del proyecto. Antes de
ponerlo frente a un cliente hay que recorrerlo entero contra un Dolibarr de
verdad y el ambiente de pruebas de Facty —timbrar, cancelar, complementar,
reconciliar— y arreglar lo que salga.

## Licencia

GPLv3. Un módulo de Dolibarr enlaza código GPL, así que este también lo es.
Ver [LICENSE](LICENSE).
