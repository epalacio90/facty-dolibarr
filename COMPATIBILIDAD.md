# Compatibilidad y soporte

## Versiones admitidas

| | Admitido | Notas |
|---|---|---|
| **Dolibarr** | 18.0 – 21.x | El módulo se niega a instalarse en versiones anteriores. |
| **PHP** | 8.1 – 8.3 | La sintaxis se verifica en las tres en cada cambio. |
| **Base de datos** | MySQL / MariaDB | Postgres no está probado; ver abajo. |
| **Facty** | Cuenta con CSD vigente y saldo de timbres | Producción y pruebas son cuentas distintas. |

**Por qué no hay compatibilidad con Dolibarr 17 y anteriores.** Dolibarr rompe
detalles internos entre versiones mayores, y sostener varias generaciones a la
vez es la deuda de mantenimiento que este módulo existe para no repetir — la
alternativa habitual es empaquetar copias del core viejo dentro del módulo, que
es precisamente cómo se llega a un módulo imposible de auditar. Preferimos
rechazar la instalación con un mensaje claro a "funcionar" de forma impredecible.

**Postgres.** Las tablas usan sintaxis MySQL (`ENGINE=innodb`, `AUTO_INCREMENT`).
Dolibarr soporta Postgres y traduce parte de esto, pero **no lo hemos probado**,
así que no lo declaramos compatible. Si lo necesitas, dilo y lo verificamos —
no lo damos por bueno sin comprobarlo.

## Qué hace y qué no

**Sí:**

- Facturas (Ingreso) y notas de crédito (Egreso)
- Complementos de pago (REP)
- Cancelación con motivos 01–04 y su acuse
- Consulta de estatus ante el SAT
- XML y PDF timbrados, en la pestaña Documentos de la factura
- CFDI relacionados y factura global
- Dos ambientes: pruebas y producción

**Todavía no:**

- **Complemento Carta Porte.** Si tu operación lo necesita, este módulo aún no
  te sirve para eso.
- **Comercio Exterior.**
- **Nómina** y **retenciones** desde Dolibarr.
- **Retenciones locales e ISH.**

Está aquí y no en letras chicas porque es la diferencia entre evaluar el módulo
en diez minutos o descubrirlo después de configurarlo.

## Convivencia con otro módulo de facturación

Se puede tener instalado otro módulo de timbrado al mismo tiempo, pero **hay que
verificarlo en tu instancia antes de confiar en ello**, y conviene hacerlo en una
copia.

El punto de fricción son los campos adicionales (extrafields). Los nuestros
llevan todos el prefijo `factymx_` justamente para no chocar, pero algunos
instaladores de terceros *modifican* definiciones de campos que no crearon. Si
eso ocurre, la configuración de uno de los dos módulos puede quedar dañada.

Recomendación práctica:

1. Instala primero en una copia de tu Dolibarr, no en producción.
2. Revisa que los campos SAT del otro módulo sigan como estaban.
3. Elige una **fecha de corte** y a partir de ahí timbra todo con uno solo.

**Los CFDI emitidos con otra herramienta no se pueden cancelar, relacionar ni
complementar desde aquí**: para eso hace falta el identificador que Facty asigna
al timbrar, y de esos comprobantes no existe. Sí puedes relacionar uno pegando su
folio fiscal a mano, que es lo que el SAT necesita. Para el resto, conserva la
herramienta anterior mientras tengas documentos vivos emitidos con ella.

## Requisitos de red

El módulo necesita salida HTTPS hacia Facty. Respeta el proxy configurado en
Dolibarr (Inicio → Configuración → Otros). No requiere ningún puerto entrante.

Si tu servidor no tiene salida a internet, el módulo no puede funcionar: el
timbrado ocurre del lado de Facty.

## Soporte

- **Alcance:** el módulo y su comunicación con Facty. No cubrimos personalizaciones
  de tu Dolibarr, otros módulos, ni la infraestructura donde corre.
- **Antes de reportar:** entra a Facty → Diagnóstico y copia la referencia
  (`request id`) del error. Con ella podemos encontrar la misma petición de
  nuestro lado; sin ella, el diagnóstico empieza de cero.
- **Versión:** indica la versión del módulo, la de Dolibarr y la de PHP.

## Seguridad

- El módulo **no guarda tu CSD ni la contraseña del PAC**. El certificado vive en
  Facty; aquí sólo hay una llave de API revocable.
- La llave se guarda cifrada y no se vuelve a mostrar completa.
- La verificación de TLS no se puede desactivar.
- La bitácora nunca registra la llave ni el contenido de las facturas: los datos
  del receptor son datos personales.

Si crees haber encontrado un problema de seguridad, escríbenos en privado antes
de publicarlo.
