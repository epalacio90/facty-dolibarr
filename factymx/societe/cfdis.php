<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    societe/cfdis.php
 * \ingroup factymx
 * \brief   CFDI y complementos de pago de un cliente.
 *
 * Una sola pestaña con las dos listas en vez de dos pestañas separadas: cuando
 * alguien revisa la situación fiscal de un cliente quiere ver ambas cosas a la
 * vez — típicamente para contestar "¿de qué factura falta el complemento?", que
 * es una pregunta que cruza las dos tablas.
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

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyConfig.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('companies', 'bills', 'factymx@factymx'));

$socid = GETPOSTINT('socid') ?: GETPOSTINT('id');

if (!$socid || !$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
    accessforbidden();
}

$env = FactyConfig::env();

llxHeader('', 'CFDI del cliente');

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'factymxcfdis', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($object, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print '<div class="fichecenter">';

print factymxEnvBanner();

// --- CFDI
print '<table class="tagtable liste"><tr class="liste_titre"><td colspan="6">Comprobantes</td></tr>';
print '<tr class="liste_titre"><td>Factura</td><td>Fecha</td><td class="right">Total</td>'
    . '<td>Estado</td><td>Folio fiscal</td><td></td></tr>';

$sql = 'SELECT f.rowid, f.ref, f.datef, f.total_ttc, c.uuid, c.status, c.env
        FROM ' . MAIN_DB_PREFIX . 'facture f
        LEFT JOIN ' . MAIN_DB_PREFIX . "factymx_cfdi c
               ON c.fk_facture = f.rowid AND c.entity = f.entity AND c.env = '" . $db->escape($env) . "'
        WHERE f.fk_soc = " . ((int) $socid) . ' AND f.entity = ' . ((int) $conf->entity) . ' AND f.fk_statut > 0
        ORDER BY f.datef DESC LIMIT 100';

$n = 0;
$resq = $db->query($sql);
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $n++;
        print '<tr class="oddeven">';
        print '<td><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . ((int) $row->rowid) . '">'
            . dol_escape_htmltag((string) $row->ref) . '</a></td>';
        print '<td>' . dol_print_date($db->jdate($row->datef), 'day') . '</td>';
        print '<td class="right">' . price((float) $row->total_ttc) . '</td>';
        print '<td>' . factymxStatusLabel((string) $row->status) . ' ' . factymxEnvBadge((string) $row->env) . '</td>';
        print '<td>' . factymxUuidShort($row->uuid) . '</td>';
        print '<td class="right"><a class="button smallpaddingimp" href="'
            . dol_buildpath('/factymx/facture/cfdi.php', 1) . '?facid=' . ((int) $row->rowid) . '">CFDI</a></td>';
        print '</tr>';
    }
    $db->free($resq);
}
if ($n === 0) {
    print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">Este cliente no tiene facturas validadas.</span></td></tr>';
}
print '</table><br>';

// --- REP
print '<table class="tagtable liste"><tr class="liste_titre"><td colspan="5">Complementos de pago</td></tr>';
print '<tr class="liste_titre"><td>Pago</td><td>Fecha</td><td class="right">Importe</td>'
    . '<td>Estado</td><td>Folio fiscal</td></tr>';

$sql = 'SELECT DISTINCT p.rowid, p.ref, p.datep, p.amount, r.uuid, r.status, r.env
        FROM ' . MAIN_DB_PREFIX . 'paiement p
        INNER JOIN ' . MAIN_DB_PREFIX . 'paiement_facture pf ON pf.fk_paiement = p.rowid
        INNER JOIN ' . MAIN_DB_PREFIX . 'facture f ON f.rowid = pf.fk_facture
        LEFT JOIN ' . MAIN_DB_PREFIX . "factymx_payment r
               ON r.fk_paiement = p.rowid AND r.entity = p.entity AND r.env = '" . $db->escape($env) . "'
        WHERE f.fk_soc = " . ((int) $socid) . ' AND p.entity = ' . ((int) $conf->entity) . '
        ORDER BY p.datep DESC LIMIT 100';

$n = 0;
$resq = $db->query($sql);
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $n++;
        print '<tr class="oddeven">';
        print '<td><a href="' . dol_buildpath('/factymx/paiement/rep.php', 1) . '?id=' . ((int) $row->rowid) . '">'
            . dol_escape_htmltag((string) ($row->ref ?: ('#' . $row->rowid))) . '</a></td>';
        print '<td>' . dol_print_date($db->jdate($row->datep), 'day') . '</td>';
        print '<td class="right">' . price((float) $row->amount) . '</td>';
        print '<td>' . factymxStatusLabel((string) $row->status) . ' ' . factymxEnvBadge((string) $row->env) . '</td>';
        print '<td>' . factymxUuidShort($row->uuid) . '</td>';
        print '</tr>';
    }
    $db->free($resq);
}
if ($n === 0) {
    print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">Sin pagos registrados.</span></td></tr>';
}
print '</table>';

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
