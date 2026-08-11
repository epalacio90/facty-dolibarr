<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    index.php
 * \ingroup factymx
 * \brief   Tablero del módulo.
 *
 * En esta sub-fase muestra el estado de la conexión y el saldo de timbres. Las
 * listas de documentos llegan en la sub-fase H; se prefiere una pantalla que
 * diga la verdad sobre lo poco que hay a una que finja secciones vacías.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
    $res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once __DIR__ . '/lib/factymx.lib.php';
require_once __DIR__ . '/class/FactyConfig.class.php';
require_once __DIR__ . '/class/FactyClient.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('factymx@factymx'));

if (!$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

llxHeader('', 'Facty');

print load_fiche_titre('Facty', '', 'factymx@factymx');
print factymxEnvBanner();

$env = FactyConfig::env();

if (!FactyConfig::isConfigured($env)) {
    print '<div class="warning">';
    print 'Facty todavía no está configurado para el ambiente <strong>' . FactyConfig::label($env) . '</strong>. ';
    if ($user->hasRight('factymx', 'config', 'write')) {
        print '<a href="' . dol_buildpath('/factymx/admin/setup.php', 1) . '">Configurar ahora</a>.';
    } else {
        print 'Pídele a un administrador que lo configure.';
    }
    print '</div>';

    llxFooter();
    $db->close();
    exit;
}

// Estado de la conexión. Una falla aquí no debe tumbar la pantalla: el tablero
// tiene que poder decir "no pude hablar con Facty" en vez de mostrar un error
// 500 que no le dice nada a nadie.
$ctx = null;
$connError = null;
try {
    $client = new FactyClient($env);
    $ctx = $client->context();
} catch (FactyConfigException $e) {
    $connError = $e->getMessage();
} catch (FactyTransportException $e) {
    $connError = $e->getMessage();
} catch (FactyApiException $e) {
    $connError = $e->userMessage();
}

print '<div class="fichecenter"><div class="fichethirdleft">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Conexión</td></tr>';

if ($connError !== null) {
    print '<tr class="oddeven"><td class="titlefield">Estado</td><td><span class="error">Sin conexión</span></td></tr>';
    print '<tr class="oddeven"><td>Detalle</td><td>' . dol_escape_htmltag($connError) . '</td></tr>';
} else {
    $org    = isset($ctx['org']['name']) ? (string) $ctx['org']['name'] : '—';
    $fiscal = isset($ctx['fiscal']) && is_array($ctx['fiscal']) ? $ctx['fiscal'] : array();
    $rfc    = isset($fiscal['rfc']) ? (string) $fiscal['rfc'] : '—';
    $csd    = isset($fiscal['csd']['state']) ? (string) $fiscal['csd']['state'] : 'desconocido';
    $mode   = isset($ctx['stampingMode']) ? (string) $ctx['stampingMode'] : '—';

    print '<tr class="oddeven"><td class="titlefield">Organización</td><td>' . dol_escape_htmltag($org) . '</td></tr>';
    print '<tr class="oddeven"><td>RFC emisor</td><td>' . dol_escape_htmltag($rfc) . '</td></tr>';
    print '<tr class="oddeven"><td>Ambiente</td><td>' . FactyConfig::label($env)
        . ' <span class="opacitymedium">(' . dol_escape_htmltag($mode) . ')</span></td></tr>';

    print '<tr class="oddeven"><td>CSD</td><td>';
    if ($csd === 'active') {
        print '<span class="ok">Vigente</span>';
    } elseif ($csd === 'expired') {
        print '<span class="error">Vencido</span> — no vas a poder timbrar hasta renovarlo en Facty.';
    } else {
        print '<span class="error">Sin CSD</span> — cárgalo en Facty antes de timbrar.';
    }
    print '</td></tr>';

    $saldo = array_key_exists('timbreBalance', $ctx) ? $ctx['timbreBalance'] : null;
    print '<tr class="oddeven"><td>Timbres disponibles</td><td>';
    if ($saldo === null) {
        print '<span class="opacitymedium">Tu llave no tiene permiso para consultar el saldo.</span>';
    } else {
        $min = (int) getDolGlobalInt('FACTYMX_MIN_TIMBRES', 10);
        print ((int) $saldo);
        if ((int) $saldo <= $min) {
            print ' <span class="error">— saldo bajo</span>';
        }
    }
    print '</td></tr>';
}

print '</table>';

// Trabajos con problemas. Va aquí arriba y no escondido en el diagnóstico: si
// algo quedó a medias, es lo primero que hay que saber al abrir el módulo.
$sql = 'SELECT COUNT(*) AS n FROM ' . MAIN_DB_PREFIX . 'factymx_job
        WHERE entity = ' . ((int) $conf->entity) . " AND env = '" . $db->escape($env) . "'
          AND status IN ('pending','failed')";
$pendientes = 0;
$resq = $db->query($sql);
if ($resq && ($rowj = $db->fetch_object($resq))) {
    $pendientes = (int) $rowj->n;
    $db->free($resq);
}

if ($pendientes > 0) {
    print '<br><div class="warning">Hay <strong>' . $pendientes . '</strong> trabajo(s) pendientes o con error. '
        . '<a href="' . dol_buildpath('/factymx/admin/diagnostics.php', 1) . '">Ver diagnóstico</a></div>';
}

print '</div>';

// --- Facturas sin timbrar: la pregunta que más se hace al abrir el módulo.
print '<div class="fichetwothirdright">';

$sql = 'SELECT f.rowid, f.ref, f.datef, f.total_ttc, s.nom AS cliente
        FROM ' . MAIN_DB_PREFIX . 'facture f
        INNER JOIN ' . MAIN_DB_PREFIX . 'societe s ON s.rowid = f.fk_soc
        LEFT JOIN ' . MAIN_DB_PREFIX . "factymx_cfdi c
               ON c.fk_facture = f.rowid AND c.entity = f.entity AND c.env = '" . $db->escape($env) . "'
        WHERE f.entity = " . ((int) $conf->entity) . " AND f.fk_statut > 0
          AND (c.rowid IS NULL OR c.status = 'failed')
        ORDER BY f.datef DESC LIMIT 10";

print '<table class="noborder centpercent"><tr class="liste_titre">';
print '<td colspan="4">Facturas sin timbrar</td></tr>';

$n = 0;
$resq = $db->query($sql);
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $n++;
        print '<tr class="oddeven">';
        print '<td><a href="' . dol_buildpath('/factymx/facture/cfdi.php', 1) . '?facid=' . ((int) $row->rowid) . '">'
            . dol_escape_htmltag((string) $row->ref) . '</a></td>';
        print '<td>' . dol_escape_htmltag(dol_trunc((string) $row->cliente, 28)) . '</td>';
        print '<td>' . dol_print_date($db->jdate($row->datef), 'day') . '</td>';
        print '<td class="right">' . price((float) $row->total_ttc) . '</td>';
        print '</tr>';
    }
    $db->free($resq);
}
if ($n === 0) {
    print '<tr class="oddeven"><td colspan="4"><span class="ok">'
        . 'No hay facturas validadas pendientes de timbrar.</span></td></tr>';
}
print '<tr class="liste_total"><td colspan="4"><a href="'
    . dol_buildpath('/factymx/consultas/facturas.php', 1) . '?filtro=sintimbrar">Ver todas</a></td></tr>';
print '</table><br>';

// --- Últimos CFDI emitidos en este ambiente.
$sql = 'SELECT f.rowid, f.ref, c.uuid, c.stamped_at, c.total, c.moneda, c.status
        FROM ' . MAIN_DB_PREFIX . 'factymx_cfdi c
        INNER JOIN ' . MAIN_DB_PREFIX . 'facture f ON f.rowid = c.fk_facture
        WHERE c.entity = ' . ((int) $conf->entity) . " AND c.env = '" . $db->escape($env) . "'
          AND c.status IN ('stamped','cancelled')
        ORDER BY c.stamped_at DESC LIMIT 10";

print '<table class="noborder centpercent"><tr class="liste_titre">';
print '<td colspan="4">Últimos CFDI</td></tr>';

$n = 0;
$resq = $db->query($sql);
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $n++;
        print '<tr class="oddeven">';
        print '<td><a href="' . dol_buildpath('/factymx/facture/cfdi.php', 1) . '?facid=' . ((int) $row->rowid) . '">'
            . dol_escape_htmltag((string) $row->ref) . '</a></td>';
        print '<td>' . factymxUuidShort($row->uuid) . '</td>';
        print '<td>' . factymxStatusLabel((string) $row->status) . '</td>';
        print '<td class="right">' . price((float) $row->total) . ' '
            . dol_escape_htmltag((string) $row->moneda) . '</td>';
        print '</tr>';
    }
    $db->free($resq);
}
if ($n === 0) {
    print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'
        . 'Todavía no se ha timbrado nada en este ambiente.</span></td></tr>';
}
print '<tr class="liste_total"><td colspan="4"><a href="'
    . dol_buildpath('/factymx/consultas/facturas.php', 1) . '?filtro=timbradas">Ver todos</a></td></tr>';
print '</table>';

print '</div></div>';

llxFooter();
$db->close();
