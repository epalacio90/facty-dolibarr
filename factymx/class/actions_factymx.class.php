<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyCfdi.class.php';

/**
 * \file    class/actions_factymx.class.php
 * \ingroup factymx
 * \brief   Ganchos sobre las pantallas estándar de Dolibarr.
 */
class ActionsFactyMX
{
    /** @var DoliDB */
    public $db;

    // SIN tipo, a propósito. Estas cuatro propiedades son el contrato con el
    // HookManager de Dolibarr, y el núcleo las REINICIA a null entre ganchos
    // (hookmanager.class.php). Declararlas `public string`/`public array`
    // provoca un TypeError fatal en cuanto se carga cualquier página que
    // dispare un gancho — no al usar el módulo, sino al abrirlo.
    //
    // La lección es más general: en las clases que instancia y manipula el
    // núcleo de Dolibarr, el tipado estricto de propiedades públicas choca con
    // un contrato que no controlamos.
    public $error = '';
    public $errors = array();
    public $results = array();
    public $resprints = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Botón de timbrado en la ficha estándar de la factura.
     *
     * Se muestra siempre que la factura esté validada y no timbrada, sin ninguna
     * ventana de tiempo artificial. El módulo anterior sólo ofrecía timbrar
     * dentro de las 72 horas siguientes a la creación, lo que deja sin salida a
     * quien factura con retraso — que es una situación común y no un error. La
     * regla real de qué se puede timbrar la pone el SAT y la aplica Facty; el
     * módulo no debe inventar una más estricta y esconder el botón.
     *
     * @param  array  $parameters
     * @param  object $object
     * @param  string $action
     * @return int
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $user, $langs;

        if (($parameters['currentcontext'] ?? '') !== 'invoicecard') {
            return 0;
        }
        if (!is_object($object) || empty($object->id)) {
            return 0;
        }
        if (!$user->hasRight('factymx', 'cfdi', 'read')) {
            return 0;
        }

        // Borrador: no hay nada que timbrar todavía.
        if ((int) $object->statut === 0) {
            return 0;
        }

        $cfdi = FactyCfdi::fetchByFacture($this->db, (int) $object->id);
        $url  = dol_buildpath('/factymx/facture/cfdi.php', 1) . '?facid=' . ((int) $object->id);

        if ($cfdi !== null && $cfdi->status === FactyCfdi::STATUS_STAMPED) {
            $this->resprints = '<a class="butActionRefused classfortooltip" href="' . $url . '" '
                . 'title="' . dol_escape_htmltag('Folio fiscal ' . $cfdi->uuid) . '">CFDI timbrado</a>';

            return 0;
        }

        if ($cfdi !== null && $cfdi->status === FactyCfdi::STATUS_PENDING) {
            // Deshabilitado a propósito: mientras el resultado sea desconocido,
            // ofrecer el botón es invitar a pagar dos veces por el mismo CFDI.
            $this->resprints = '<span class="butActionRefused classfortooltip" '
                . 'title="' . dol_escape_htmltag('Timbrado en proceso; el módulo está verificando el resultado.')
                . '">Timbrado en proceso</span>';

            return 0;
        }

        if (!$user->hasRight('factymx', 'cfdi', 'create')) {
            return 0;
        }

        $label = FactyConfig::isTest() ? 'Timbrar (pruebas)' : 'Timbrar CFDI';
        $this->resprints = '<a class="butAction" href="' . $url . '">' . $label . '</a>';

        return 0;
    }

    /**
     * Banda de MODO PRUEBAS también en las pantallas del núcleo donde se
     * timbra, no sólo en las del módulo: la ficha de la factura es justo donde
     * alguien podría confundirse de ambiente.
     *
     * @param  array  $parameters
     * @param  object $object
     * @param  string $action
     * @return int
     */
    public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
    {
        return 0;
    }
}
