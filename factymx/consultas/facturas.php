<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    consultas/facturas.php
 * \ingroup factymx
 * \brief   Facturas y su estado de timbrado.
 *
 * La lista parte de las facturas de Dolibarr, no de la tabla de CFDI: lo que
 * casi siempre se quiere saber es qué falta por timbrar, y eso son justo las
 * facturas que NO tienen fila en `llx_factymx_cfdi`. Una lista construida al
 * revés no puede contestar esa pregunta.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyConfig.class.php';
require_once __DIR__ . '/../class/FactyCfdi.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('bills', 'factymx@factymx'));

if (!$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$filtro  = GETPOST('filtro', 'aZ09') ?: 'todas';
$page    = GETPOSTINT('page') > 0 ? GETPOSTINT('page') : 0;
$limit   = 50;
$offset  = $page * $limit;
$env     = FactyConfig::env();

$where = 'f.entity = ' . ((int) $conf->entity) . ' AND f.fk_statut > 0';

switch ($filtro) {
    case 'sintimbrar':
        // El estado "sin timbrar" es la AUSENCIA de fila, no un valor: una
        // factura recién validada no tiene registro todavía.
        $where .= " AND (c.rowid IS NULL OR c.status = 'failed')";
        break;
    case 'timbradas':
        $where .= " AND c.status = 'stamped'";
        break;
    case 'canceladas':
        $where .= " AND c.status = 'cancelled'";
        break;
    case 'proceso':
        $where .= " AND c.status = 'pending'";
        break;
}

$sql = 'SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.type, s.nom AS cliente, s.rowid AS socid,
               c.uuid, c.status, c.stamped_at, c.moneda, c.env, c.last_error
        FROM ' . MAIN_DB_PREFIX . 'facture f
        INNER JOIN ' . MAIN_DB_PREFIX . 'societe s ON s.rowid = f.fk_soc
        LEFT JOIN ' . MAIN_DB_PREFIX . "factymx_cfdi c
               ON c.fk_facture = f.rowid AND c.entity = f.entity AND c.env = '" . $db->escape($env) . "'
        WHERE " . $where . '
        ORDER BY f.datef DESC, f.rowid DESC';

$sqlCount = 'SELECT COUNT(*) AS n FROM ' . MAIN_DB_PREFIX . 'facture f
        INNER JOIN ' . MAIN_DB_PREFIX . 'societe s ON s.rowid = f.fk_soc
        LEFT JOIN ' . MAIN_DB_PREFIX . "factymx_cfdi c
               ON c.fk_facture = f.rowid AND c.entity = f.entity AND c.env = '" . $db->escape($env) . "'
        WHERE " . $where;

$total = 0;
$resc = $db->query($sqlCount);
if ($resc && ($rowc = $db->fetch_object($resc))) {
    $total = (int) $rowc->n;
    $db->free($resc);
}

$resq = $db->query($sql . ' ' . $db->plimit($limit, $offset));

llxHeader('', 'Facturas CFDI');

print load_fiche_titre('Facturas CFDI', '', 'factymx@factymx');
print factymxEnvBanner();

// Filtros. Se muestran como enlaces y no como un formulario porque son cuatro
// vistas fijas, no una búsqueda: un enlace se puede marcar y compartir.
$filtros = array(
    'todas'      => 'Todas',
    'sintimbrar' => 'Sin timbrar',
    'proceso'    => 'En proceso',
    'timbradas'  => 'Timbradas',
    'canceladas' => 'Canceladas',
);
print '<div class="tabsAction">';
foreach ($filtros as $key => $label) {
    $cls = ($filtro === $key) ? 'butActionRefused' : 'butAction';
    print '<a class="' . $cls . '" href="' . $_SERVER['PHP_SELF'] . '?filtro=' . $key . '">'
        . dol_escape_htmltag($label) . '</a>';
}
print '</div>';

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';
print '<tr class="liste_titre">';
print '<td>Factura</td><td>Cliente</td><td>Fecha</td><td class="right">Total</td>';
print '<td>Estado</td><td>Folio fiscal</td><td>Timbrado</td><td></td>';
print '</tr>';

$n = 0;
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $n++;
        $esEgreso = ((int) $row->type === Facture::TYPE_CREDIT_NOTE);

        print '<tr class="oddeven">';
        print '<td><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . ((int) $row->rowid) . '">'
            . dol_escape_htmltag((string) $row->ref) . '</a>';
        if ($esEgreso) {
            print ' <span class="opacitymedium">(NC)</span>';
        }
        print '</td>';
        print '<td><a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . ((int) $row->socid) . '">'
            . dol_escape_htmltag((string) $row->cliente) . '</a></td>';
        print '<td>' . dol_print_date($db->jdate($row->datef), 'day') . '</td>';
        print '<td class="right">' . price((float) $row->total_ttc) . '</td>';
        print '<td>' . factymxStatusLabel((string) $row->status);
        if ($row->env) {
            print ' ' . factymxEnvBadge((string) $row->env);
        }
        // El error se muestra en la propia lista: obligar a entrar documento por
        // documento para averiguar por qué falló convierte diez fallos en diez
        // navegaciones.
        if ($row->status === 'failed' && $row->last_error) {
            print '<br><span class="opacitymedium" title="' . dol_escape_htmltag((string) $row->last_error) . '">'
                . dol_escape_htmltag(dol_trunc((string) $row->last_error, 60)) . '</span>';
        }
        print '</td>';
        print '<td>' . factymxUuidShort($row->uuid) . '</td>';
        print '<td>' . ($row->stamped_at ? dol_print_date($db->jdate($row->stamped_at), 'dayhour') : '') . '</td>';
        print '<td class="right"><a class="button smallpaddingimp" href="'
            . dol_buildpath('/factymx/facture/cfdi.php', 1) . '?facid=' . ((int) $row->rowid) . '">CFDI</a></td>';
        print '</tr>';
    }
    $db->free($resq);
}

if ($n === 0) {
    print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">Sin resultados.</span></td></tr>';
}

print '</table></div>';

// Paginación mínima: sólo lo necesario para no cargar miles de filas de golpe.
if ($total > $limit) {
    print '<div class="center"><br>';
    if ($page > 0) {
        print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?filtro=' . $filtro . '&page=' . ($page - 1) . '">« Anteriores</a> ';
    }
    print '<span class="opacitymedium">' . ($offset + 1) . '–' . min($offset + $limit, $total) . ' de ' . $total . '</span>';
    if ($offset + $limit < $total) {
        print ' <a class="button" href="' . $_SERVER['PHP_SELF'] . '?filtro=' . $filtro . '&page=' . ($page + 1) . '">Siguientes »</a>';
    }
    print '</div>';
}

llxFooter();
$db->close();
