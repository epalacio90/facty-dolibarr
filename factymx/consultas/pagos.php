<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    consultas/pagos.php
 * \ingroup factymx
 * \brief   Pagos y su complemento (REP).
 *
 * Igual que la lista de facturas, parte de los pagos de Dolibarr para poder
 * contestar "¿a qué pago le falta su complemento?" — que es la pregunta que
 * genera multas.
 *
 * Se listan sólo los pagos de facturas PPD: un pago sobre una factura PUE no
 * lleva complemento, e incluirlo llenaría la lista de filas que nunca van a
 * cambiar de estado y esconderían las que sí importan.
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

require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyConfig.class.php';
require_once __DIR__ . '/../class/FactyPayment.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('bills', 'banks', 'factymx@factymx'));

if (!$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$filtro = GETPOST('filtro', 'aZ09') ?: 'todos';
$page   = GETPOSTINT('page') > 0 ? GETPOSTINT('page') : 0;
$limit  = 50;
$offset = $page * $limit;
$env    = FactyConfig::env();

$where = 'p.entity = ' . ((int) $conf->entity);

switch ($filtro) {
    case 'sintimbrar':
        $where .= " AND (r.rowid IS NULL OR r.status = 'failed')";
        break;
    case 'timbrados':
        $where .= " AND r.status = 'stamped'";
        break;
    case 'cancelados':
        $where .= " AND r.status = 'cancelled'";
        break;
    case 'proceso':
        $where .= " AND r.status = 'pending'";
        break;
}

$sql = 'SELECT p.rowid, p.ref, p.datep, p.amount, p.num_paiement,
               r.uuid, r.status, r.stamped_at, r.env, r.last_error,
               (SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'paiement_facture pf WHERE pf.fk_paiement = p.rowid) AS nfact
        FROM ' . MAIN_DB_PREFIX . 'paiement p
        LEFT JOIN ' . MAIN_DB_PREFIX . "factymx_payment r
               ON r.fk_paiement = p.rowid AND r.entity = p.entity AND r.env = '" . $db->escape($env) . "'
        WHERE " . $where . '
        ORDER BY p.datep DESC, p.rowid DESC';

$resq = $db->query($sql . ' ' . $db->plimit($limit, $offset));

llxHeader('', 'Complementos de pago');

print load_fiche_titre('Complementos de pago', '', 'factymx@factymx');
print factymxEnvBanner();

$filtros = array(
    'todos'      => 'Todos',
    'sintimbrar' => 'Sin complemento',
    'proceso'    => 'En proceso',
    'timbrados'  => 'Timbrados',
    'cancelados' => 'Cancelados',
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
print '<td>Pago</td><td>Fecha</td><td class="right">Importe</td><td>Facturas</td>';
print '<td>Estado</td><td>Folio fiscal</td><td></td>';
print '</tr>';

$n = 0;
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $n++;
        print '<tr class="oddeven">';
        print '<td><a href="' . DOL_URL_ROOT . '/compta/paiement/card.php?id=' . ((int) $row->rowid) . '">'
            . dol_escape_htmltag((string) ($row->ref ?: ('#' . $row->rowid))) . '</a></td>';
        print '<td>' . dol_print_date($db->jdate($row->datep), 'day') . '</td>';
        print '<td class="right">' . price((float) $row->amount) . '</td>';
        print '<td>' . ((int) $row->nfact) . '</td>';
        print '<td>' . factymxStatusLabel((string) $row->status);
        if ($row->env) {
            print ' ' . factymxEnvBadge((string) $row->env);
        }
        if ($row->status === 'failed' && $row->last_error) {
            print '<br><span class="opacitymedium" title="' . dol_escape_htmltag((string) $row->last_error) . '">'
                . dol_escape_htmltag(dol_trunc((string) $row->last_error, 60)) . '</span>';
        }
        print '</td>';
        print '<td>' . factymxUuidShort($row->uuid) . '</td>';
        print '<td class="right"><a class="button smallpaddingimp" href="'
            . dol_buildpath('/factymx/paiement/rep.php', 1) . '?id=' . ((int) $row->rowid) . '">REP</a></td>';
        print '</tr>';
    }
    $db->free($resq);
}

if ($n === 0) {
    print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">Sin resultados.</span></td></tr>';
}

print '</table></div>';

if ($page > 0 || $n === $limit) {
    print '<div class="center"><br>';
    if ($page > 0) {
        print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?filtro=' . $filtro . '&page=' . ($page - 1) . '">« Anteriores</a> ';
    }
    if ($n === $limit) {
        print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?filtro=' . $filtro . '&page=' . ($page + 1) . '">Siguientes »</a>';
    }
    print '</div>';
}

llxFooter();
$db->close();
