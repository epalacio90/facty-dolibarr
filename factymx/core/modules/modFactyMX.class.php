<?php

/* Copyright (C) 2026 Facty
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    core/modules/modFactyMX.class.php
 * \ingroup factymx
 * \brief   Descriptor del módulo Facty — timbrado CFDI 4.0 desde Dolibarr.
 *
 * Facty es la fuente de verdad fiscal. Este módulo NO construye XML, NO guarda
 * el CSD, NO guarda la contraseña del PAC y NO calcula la cadena original:
 * manda la intención de negocio como JSON y Facty valida, timbra y almacena.
 *
 * Dos ambientes (§2.9 del plan): producción → facty.mx, pruebas →
 * preview.facty.mx. Ambos se configuran a la vez y se alterna con un radio;
 * cada registro local queda marcado con el ambiente en el que se creó.
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modFactyMX extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Reservar en la wiki de Dolibarr antes de publicar (T-16.K.5).
        $this->numero = 500180;
        $this->rights_class = 'factymx';

        $this->family = 'financial';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'Timbrado de CFDI 4.0 con Facty: facturas, notas de crédito y complementos de pago.';
        $this->descriptionlong = 'Conecta Dolibarr con Facty por API REST para timbrar CFDI 4.0 ante el SAT. '
            . 'El certificado (CSD) y las credenciales del PAC viven en Facty, nunca en este servidor.';
        $this->editor_name = 'Facty';
        $this->editor_url = 'https://facty.mx';
        $this->version = '0.1.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'factymx@factymx';

        // Sólo Dolibarr 18+. No hay directorio backport/ y no se aceptan cores
        // anteriores: sostener varias generaciones de internals es la deuda de
        // mantenimiento que este módulo existe para no repetir (R-16.9).
        $this->need_dolibarr_version = array(18, 0);
        $this->phpmin = array(8, 1);

        $this->depends = array('modFacture', 'modProduct', 'modBanque');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('factymx');

        $this->module_parts = array(
            'triggers' => 1,
            'hooks'    => array('invoicecard', 'paiementcard', 'formmail', 'main'),
            'css'      => array('/factymx/css/factymx.css'),
        );

        $this->dirs = array();

        // ---------------------------------------------------------------
        // Configuración (por entity). El par producción/pruebas es
        // deliberado: se configuran ambos y se alterna con FACTYMX_ENV, igual
        // que el par de URLs que estos usuarios ya conocen de su módulo
        // actual. Las llaves se guardan cifradas (dolEncrypt) y nunca se
        // vuelven a mostrar completas.
        //
        // Producción y pruebas son BASES DE DATOS DISTINTAS en Facty, así que
        // la llave, el slug de la organización y cualquier id (cliente,
        // producto, cuenta) de un ambiente no significan nada en el otro.
        // ---------------------------------------------------------------
        $this->const = array(
            array('FACTYMX_ENV',           'chaine', 'test',                          'Ambiente activo: prod | test', 0, 'current', 1),
            array('FACTYMX_API_BASE_PROD', 'chaine', 'https://facty.mx/api',          'URL base de Facty (producción)', 0, 'current', 1),
            array('FACTYMX_API_BASE_TEST', 'chaine', 'https://preview.facty.mx/api',  'URL base de Facty (pruebas)', 0, 'current', 1),
            array('FACTYMX_TIMEOUT',       'chaine', '30',                            'Timeout HTTP en segundos', 0, 'current', 1),
            array('FACTYMX_MIN_TIMBRES',   'chaine', '10',                            'Avisar cuando el saldo de timbres baje de', 0, 'current', 1),
            array('FACTYMX_CATALOG_TTL',   'chaine', '7',                             'Días de caché de catálogos SAT', 0, 'current', 1),
            // Timbrado automático al validar: apagado por omisión, y así debe
            // quedarse salvo decisión explícita. Validar una factura es una
            // acción de Dolibarr; timbrar emite un comprobante fiscal y gasta
            // dinero. Encadenarlas sin que nadie lo pida convierte un clic en
            // una pantalla que no habla de impuestos en un CFDI ante el SAT.
            array('FACTYMX_AUTOSTAMP',     'chaine', '0',                             'Timbrar automáticamente al validar la factura', 0, 'current', 1),
        );

        // ---------------------------------------------------------------
        // Permisos. Cancelar es su propio permiso y no viene incluido en
        // "timbrar": cancelar destruye un comprobante fiscal ya emitido y
        // gasta un timbre, así que no debe caer por omisión en quien sólo
        // necesita facturar.
        // ---------------------------------------------------------------
        $r = 0;
        $this->rights = array();

        $this->rights[$r][0] = $this->numero + 1;
        $this->rights[$r][1] = 'Consultar CFDI timbrados';
        $this->rights[$r][4] = 'cfdi';
        $this->rights[$r][5] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero + 2;
        $this->rights[$r][1] = 'Timbrar facturas y notas de crédito';
        $this->rights[$r][4] = 'cfdi';
        $this->rights[$r][5] = 'create';
        $r++;

        $this->rights[$r][0] = $this->numero + 3;
        $this->rights[$r][1] = 'Cancelar CFDI ante el SAT';
        $this->rights[$r][4] = 'cfdi';
        $this->rights[$r][5] = 'cancel';
        $r++;

        $this->rights[$r][0] = $this->numero + 4;
        $this->rights[$r][1] = 'Timbrar complementos de pago (REP)';
        $this->rights[$r][4] = 'rep';
        $this->rights[$r][5] = 'create';
        $r++;

        $this->rights[$r][0] = $this->numero + 5;
        $this->rights[$r][1] = 'Cancelar complementos de pago (REP)';
        $this->rights[$r][4] = 'rep';
        $this->rights[$r][5] = 'cancel';
        $r++;

        $this->rights[$r][0] = $this->numero + 6;
        $this->rights[$r][1] = 'Acceso a la configuración de Facty';
        $this->rights[$r][4] = 'config';
        $this->rights[$r][5] = 'write';
        $r++;

        // ---------------------------------------------------------------
        // Pestañas y menús.
        //
        // Aquí sólo se declara lo que EXISTE. Las pestañas de factura y de pago,
        // y las listas de CFDI, llegan en las sub-fases D–H; declararlas antes
        // de tiempo dejaría al usuario con enlaces que llevan a un 404, que es
        // peor que no ofrecerlas todavía.
        // ---------------------------------------------------------------
        $this->tabs = array(
            'invoice:+factymx:CFDI:factymx@factymx:'
                . '$user->hasRight(\'factymx\', \'cfdi\', \'read\'):/factymx/facture/cfdi.php?facid=__ID__',
            'thirdparty:+factymxfiscal:Datos fiscales CFDI:factymx@factymx:'
                . '$user->hasRight(\'factymx\', \'cfdi\', \'read\'):/factymx/societe/fiscal.php?socid=__ID__',
            'product:+factymxsat:Datos SAT:factymx@factymx:'
                . '$user->hasRight(\'factymx\', \'cfdi\', \'read\'):/factymx/product/sat.php?id=__ID__',
        );

        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu'  => '',
            'type'     => 'top',
            'titre'    => 'Facty',
            'prefix'   => '',
            'mainmenu' => 'factymx',
            'leftmenu' => '',
            'url'      => '/factymx/index.php',
            'langs'    => 'factymx',
            'position' => 1000 + $r,
            'enabled'  => '$conf->factymx->enabled',
            'perms'    => '$user->hasRight("factymx", "cfdi", "read")',
            'target'   => '',
            'user'     => 2,
        );

        // ---------------------------------------------------------------
        // Trabajo programado: reintentos y reconciliación.
        //
        // La reconciliación no es un lujo. Un timeout de red NO significa "no
        // se timbró" — puede que la petición sí haya llegado. La única
        // respuesta segura es resolver contra Facty por idempotencyKey antes
        // de reintentar; reintentar a ciegas gasta timbres de verdad.
        // ---------------------------------------------------------------
        $this->cronjobs = array(
            array(
                'label'         => 'Facty: reintentos y reconciliación',
                'jobtype'       => 'method',
                'class'         => '/factymx/class/FactyJob.class.php',
                'objectname'    => 'FactyJob',
                'method'        => 'runPending',
                'parameters'    => '',
                'comment'       => 'Reintenta timbrados/cancelaciones pendientes, reconcilia por idempotencyKey y refresca el caché de catálogos.',
                'frequency'     => 5,
                'unitfrequency' => 60,
                'status'        => 1,
                'test'          => '$conf->factymx->enabled',
                'priority'      => 50,
            ),
        );

        $this->boxes = array();
        $this->export_array = array();
        $this->import_array = array();
    }

    /**
     * Instalación. Las tablas se crean desde sql/ (todas con IF NOT EXISTS),
     * así que reinstalar o actualizar no destruye datos.
     *
     * @param  string $options
     * @return int
     */
    public function init($options = '')
    {
        global $conf, $langs;

        $result = $this->_load_tables('/factymx/sql/');
        if ($result < 0) {
            return -1;
        }

        $this->createExtrafields();

        $sql = array();

        return $this->_init($sql, $options);
    }

    /**
     * Crea los extrafields, de forma idempotente.
     *
     * **Todos llevan el prefijo `factymx_` a propósito.** Otro módulo de
     * facturación puede tener ya sus propios extrafields con los nombres obvios
     * (claveprodserv, umed, usocfdi…) sobre estas mismas tablas, y su instalador
     * MODIFICA definiciones que no creó. Compartir nombres corrompería la
     * configuración de cualquiera de los dos, justo en las instalaciones que más
     * interesa convertir: las que ya facturan con otra cosa.
     *
     * Los campos son de texto, no listas ligadas a una tabla: el catálogo vive
     * en la caché local, que está acotada por ambiente, y el filtro estático de
     * una lista ligada no puede expresar "sólo el ambiente activo". Los
     * selectores buenos están en las pestañas del módulo; aquí sólo se define el
     * almacenamiento.
     */
    private function createExtrafields(): void
    {
        global $db, $conf, $langs;

        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $extra = new ExtraFields($db);

        // Producto: lo que el SAT exige por concepto.
        $extra->addExtraField('factymx_claveprodserv', 'Clave producto/servicio (SAT)', 'varchar', 100, 20, 'product', 0, 0, '', '', 1, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_claveunidad', 'Clave de unidad (SAT)', 'varchar', 101, 5, 'product', 0, 0, '', '', 1, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_noidentificacion', 'No. de identificación', 'varchar', 102, 100, 'product', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_objetoimp', 'Objeto de impuesto', 'varchar', 103, 2, 'product', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');

        // Línea de factura: los mismos campos, como excepción por concepto.
        // Una línea puede diferir de su producto, y las líneas de texto libre
        // (sin producto de catálogo) necesitan sus propias claves.
        $extra->addExtraField('factymx_claveprodserv', 'Clave producto/servicio (SAT)', 'varchar', 100, 20, 'facturedet', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_claveunidad', 'Clave de unidad (SAT)', 'varchar', 101, 5, 'facturedet', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_objetoimp', 'Objeto de impuesto', 'varchar', 102, 2, 'facturedet', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');

        // Factura: datos del comprobante.
        $extra->addExtraField('factymx_usocfdi', 'Uso del CFDI', 'varchar', 100, 4, 'facture', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_metodopago', 'Método de pago (PUE/PPD)', 'varchar', 101, 3, 'facture', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_exportacion', 'Exportación', 'varchar', 102, 2, 'facture', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');

        // Tercero: datos fiscales del receptor.
        $extra->addExtraField('factymx_regimenfiscal', 'Régimen fiscal', 'varchar', 100, 4, 'societe', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
        $extra->addExtraField('factymx_usocfdi', 'Uso del CFDI por omisión', 'varchar', 101, 4, 'societe', 0, 0, '', '', 0, '', 0, 0, '', '', 'factymx@factymx', '$conf->factymx->enabled');
    }

    /**
     * Desinstalación. NO borra las tablas: contienen el mapeo
     * fk_facture ↔ id de Facty ↔ UUID, que es la única forma de volver a
     * relacionar un CFDI ya timbrado con su factura de Dolibarr. Perderlo
     * dejaría comprobantes fiscales huérfanos.
     *
     * @param  string $options
     * @return int
     */
    public function remove($options = '')
    {
        $sql = array();

        return $this->_remove($sql, $options);
    }
}
