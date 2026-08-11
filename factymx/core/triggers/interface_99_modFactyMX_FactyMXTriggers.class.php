<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/**
 * \file    core/triggers/interface_99_modFactyMX_FactyMXTriggers.class.php
 * \ingroup factymx
 * \brief   Timbrado automático al validar una factura. APAGADO por omisión.
 *
 * Está apagado por omisión y así debe quedarse salvo decisión explícita del
 * cliente: validar una factura es una acción de Dolibarr, timbrar es emitir un
 * comprobante fiscal y gastar dinero. Encadenar las dos sin que nadie lo pida
 * significa que un clic equivocado en una pantalla que no habla de impuestos
 * emite un CFDI ante el SAT — que después sólo se corrige cancelando, con otro
 * timbre y un motivo que justificar.
 *
 * Cuando se enciende, los errores NO tumban la validación de la factura: la
 * factura ya quedó validada en Dolibarr y hacer fallar el trigger dejaría al
 * usuario con una operación a medias y sin saber qué parte se aplicó.
 */
class InterfaceFactyMXTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;

        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'financial';
        $this->description = 'Timbrado automático de CFDI con Facty.';
        $this->version = '0.1.0';
        $this->picto = 'factymx@factymx';
    }

    /**
     * @param  string      $action
     * @param  CommonObject $object
     * @param  User        $user
     * @param  Translate   $langs
     * @param  Conf        $conf
     * @return int
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (empty($conf->factymx) || empty($conf->factymx->enabled)) {
            return 0;
        }

        if ($action !== 'BILL_VALIDATE') {
            return 0;
        }

        if (!getDolGlobalString('FACTYMX_AUTOSTAMP')) {
            return 0;
        }

        if (!$user->hasRight('factymx', 'cfdi', 'create')) {
            return 0;
        }

        require_once __DIR__ . '/../../lib/factymx.lib.php';
        require_once __DIR__ . '/../../class/FactyStamp.class.php';

        try {
            $stamp  = new FactyStamp($this->db);
            $result = $stamp->stamp($object, array());

            if ($result === null) {
                // Se avisa, no se falla. La factura ya está validada; devolver
                // error aquí revertiría o ensuciaría esa operación por algo que
                // el usuario puede resolver desde la pestaña CFDI.
                $msg = $stamp->error !== '' ? $stamp->error : implode(' ', $stamp->problems);
                setEventMessages(
                    'La factura se validó, pero no se pudo timbrar automáticamente: ' . $msg
                    . ' Puedes timbrarla desde la pestaña CFDI.',
                    null,
                    'warnings'
                );
            }
        } catch (Exception $e) {
            dol_syslog('FactyMX autostamp: ' . $e->getMessage(), LOG_ERR);
            setEventMessages(
                'La factura se validó, pero el timbrado automático falló: ' . $e->getMessage(),
                null,
                'warnings'
            );
        }

        return 0;
    }
}
