<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    admin/diagnostics.php
 * \ingroup factymx
 * \brief   Últimas llamadas a la API y trabajos pendientes.
 *
 * Existe para que un ticket de soporte llegue con datos en vez de con "no me
 * timbra". Muestra el código de error de Facty y su request id, que es lo que
 * permite encontrar la misma petición del otro lado.
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyConfig.class.php';
require_once __DIR__ . '/../class/FactyJob.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('admin', 'factymx@factymx'));

if (!$user->admin && !$user->hasRight('factymx', 'config', 'write')) {
    accessforbidden();
}

$env    = FactyConfig::env();
$action = GETPOST('action', 'aZ09');

// Reintentar un trabajo agotado. Se limita a poner el contador a cero y la
// próxima ejecución en "ahora": el trabajo sigue siendo una RECONCILIACIÓN, así
// que reintentarlo pregunta de nuevo, no vuelve a timbrar. Sin este botón, un
// trabajo agotado sólo se recupera tocando la base a mano.
if ($action === 'retry' && $user->hasRight('factymx', 'config', 'write')) {
    $jobId = GETPOSTINT('job');
    if ($jobId > 0) {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . "factymx_job
                SET status = '" . $db->escape(FactyJob::STATUS_PENDING) . "', attempts = 0,
                    next_run_at = '" . $db->idate(dol_now()) . "', last_error = NULL
                WHERE rowid = " . $jobId . ' AND entity = ' . ((int) $conf->entity);
        if ($db->query($sql)) {
            setEventMessages('Trabajo reprogramado. Se ejecutará en la próxima pasada del cron.', null, 'mesgs');
        }
    }
}

llxHeader('', 'Facty — Diagnóstico');
print load_fiche_titre('Facty — Configuración', '', 'factymx@factymx');
print dol_get_fiche_head(factymxAdminPrepareHead(), 'diagnostics', 'Facty', -1, 'factymx@factymx');
print factymxEnvBanner();

// --- Trabajos pendientes o fallidos -------------------------------------
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre">'
    . '<td colspan="6">Trabajos en cola (' . FactyConfig::label($env) . ')</td></tr>';
print '<tr class="liste_titre"><td>Tipo</td><td>Referencia</td><td>Intentos</td>'
    . '<td>Próximo intento</td><td>Estado</td><td>Último error</td><td></td></tr>';

$sql = 'SELECT rowid, kind, ref_table, ref_id, attempts, next_run_at, status, last_error
        FROM ' . MAIN_DB_PREFIX . "factymx_job
        WHERE entity = " . ((int) $conf->entity) . " AND env = '" . $db->escape($env) . "'
          AND status <> 'done'
        ORDER BY rowid DESC LIMIT 50";

$resq = $db->query($sql);
$njobs = 0;
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $njobs++;
        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($row->kind) . '</td>';
        print '<td>' . dol_escape_htmltag($row->ref_table . ' #' . $row->ref_id) . '</td>';
        print '<td>' . ((int) $row->attempts) . '</td>';
        print '<td>' . dol_escape_htmltag((string) $row->next_run_at) . '</td>';
        print '<td class="factymx-status-' . dol_escape_htmltag($row->status) . '">'
            . dol_escape_htmltag($row->status) . '</td>';
        print '<td>' . dol_escape_htmltag((string) $row->last_error) . '</td>';
        print '<td class="right">';
        if ($row->status === FactyJob::STATUS_FAILED && $user->hasRight('factymx', 'config', 'write')) {
            print '<a class="button smallpaddingimp" href="' . $_SERVER['PHP_SELF']
                . '?action=retry&job=' . ((int) $row->rowid) . '&token=' . newToken() . '">Reintentar</a>';
        }
        print '</td>';
        print '</tr>';
    }
    $db->free($resq);
}
if ($njobs === 0) {
    print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">Sin trabajos pendientes.</span></td></tr>';
}
print '</table></div><br>';

// --- Últimas llamadas ---------------------------------------------------
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre">'
    . '<td colspan="6">Últimas llamadas a Facty</td></tr>';
print '<tr class="liste_titre"><td>Fecha</td><td>Operación</td><td>HTTP</td>'
    . '<td>Código</td><td>Request id</td><td>Duración</td></tr>';

$sql = 'SELECT tms, action, method, path, http_status, facty_code, facty_request_id, duration_ms, message
        FROM ' . MAIN_DB_PREFIX . "factymx_log
        WHERE entity = " . ((int) $conf->entity) . " AND env = '" . $db->escape($env) . "'
        ORDER BY rowid DESC LIMIT 100";

$resq = $db->query($sql);
$nlogs = 0;
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $nlogs++;
        $status = (int) $row->http_status;
        $cls = $status === 0 ? 'factymx-status-failed'
            : ($status >= 400 ? 'factymx-status-failed' : 'factymx-status-stamped');

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag((string) $row->tms) . '</td>';
        print '<td>' . dol_escape_htmltag(trim($row->method . ' ' . $row->path)) . '</td>';
        // 0 significa que la petición nunca llegó — se distingue de un error
        // devuelto por Facty, porque la acción a tomar es distinta.
        print '<td class="' . $cls . '">' . ($status === 0 ? 'sin respuesta' : $status) . '</td>';
        print '<td>' . dol_escape_htmltag((string) $row->facty_code) . '</td>';
        print '<td><span class="factymx-request-id">'
            . dol_escape_htmltag((string) $row->facty_request_id) . '</span></td>';
        print '<td>' . ($row->duration_ms === null ? '' : ((int) $row->duration_ms) . ' ms') . '</td>';
        print '</tr>';
    }
    $db->free($resq);
}
if ($nlogs === 0) {
    print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'
        . 'Todavía no hay llamadas registradas en este ambiente.</span></td></tr>';
}
print '</table></div>';

print '<br><span class="opacitymedium">'
    . 'La bitácora nunca guarda la API key ni el contenido de la factura: los datos del receptor '
    . '(RFC, nombre, código postal) son datos personales.'
    . '</span>';

print dol_get_fiche_end();
llxFooter();
$db->close();
