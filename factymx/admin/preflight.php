<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    admin/preflight.php
 * \ingroup factymx
 * \brief   Qué falta antes de poder timbrar.
 *
 * Reemplaza la carga masiva por Excel del módulo anterior con algo más simple y
 * más honesto: primero decir qué falta, y sólo entonces ofrecer arreglarlo.
 *
 * El orden importa. Descubrir que a 300 productos les falta la clave del SAT
 * cuando ya estás intentando timbrar la factura de un cliente que espera es la
 * peor forma de enterarse. Esta pantalla existe para que eso pase el día de la
 * instalación y no el día de la primera factura.
 *
 * CSV en vez de Excel: se abre en cualquier hoja de cálculo, no necesita una
 * biblioteca de terceros dentro del módulo, y se puede revisar en un editor de
 * texto cuando algo sale mal.
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

global $db, $langs, $user, $conf;

$langs->loadLangs(array('admin', 'factymx@factymx'));

if (!$user->admin && !$user->hasRight('factymx', 'config', 'write')) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$entity = (int) $conf->entity;

/** Productos usados en facturas a los que les falta clave o unidad. */
function factymxProductsMissing($db, int $entity, int $limit = 500): array
{
    $sql = 'SELECT p.rowid, p.ref, p.label,
                   ecp.factymx_claveprodserv AS clave,
                   ecp.factymx_claveunidad   AS unidad
            FROM ' . MAIN_DB_PREFIX . 'product p
            LEFT JOIN ' . MAIN_DB_PREFIX . 'product_extrafields ecp ON ecp.fk_object = p.rowid
            WHERE p.entity = ' . $entity . '
              AND p.tosell = 1
              AND (ecp.factymx_claveprodserv IS NULL OR ecp.factymx_claveprodserv = ""
                   OR ecp.factymx_claveunidad IS NULL OR ecp.factymx_claveunidad = "")
            ORDER BY p.ref
            LIMIT ' . ((int) $limit);

    $out = array();
    $resq = $db->query($sql);
    if ($resq) {
        while ($row = $db->fetch_object($resq)) {
            $out[] = $row;
        }
        $db->free($resq);
    }

    return $out;
}

/** Terceros con facturas a los que les falta RFC, CP o régimen fiscal. */
function factymxClientsMissing($db, int $entity, int $limit = 500): array
{
    $sql = 'SELECT s.rowid, s.nom, s.idprof1, s.zip,
                   ese.factymx_regimenfiscal AS regimen
            FROM ' . MAIN_DB_PREFIX . 'societe s
            LEFT JOIN ' . MAIN_DB_PREFIX . 'societe_extrafields ese ON ese.fk_object = s.rowid
            WHERE s.entity = ' . $entity . '
              AND s.client > 0
              AND (s.idprof1 IS NULL OR s.idprof1 = ""
                   OR s.zip IS NULL OR s.zip = ""
                   OR ese.factymx_regimenfiscal IS NULL OR ese.factymx_regimenfiscal = "")
            ORDER BY s.nom
            LIMIT ' . ((int) $limit);

    $out = array();
    $resq = $db->query($sql);
    if ($resq) {
        while ($row = $db->fetch_object($resq)) {
            $out[] = $row;
        }
        $db->free($resq);
    }

    return $out;
}

// --- Exportación CSV ----------------------------------------------------
if ($action === 'export_products' || $action === 'export_clients') {
    $isProducts = ($action === 'export_products');
    $name = 'facty-faltantes-' . ($isProducts ? 'productos' : 'clientes') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    $out = fopen('php://output', 'w');
    // BOM: sin él, Excel en Windows abre el archivo en la codificación
    // equivocada y destroza los acentos de las razones sociales.
    fwrite($out, "\xEF\xBB\xBF");

    if ($isProducts) {
        fputcsv($out, array('id', 'referencia', 'descripcion', 'clave_prod_serv', 'clave_unidad'));
        foreach (factymxProductsMissing($db, $entity, 100000) as $r) {
            fputcsv($out, array($r->rowid, $r->ref, $r->label, $r->clave, $r->unidad));
        }
    } else {
        fputcsv($out, array('id', 'nombre', 'rfc', 'codigo_postal', 'regimen_fiscal'));
        foreach (factymxClientsMissing($db, $entity, 100000) as $r) {
            fputcsv($out, array($r->rowid, $r->nom, $r->idprof1, $r->zip, $r->regimen));
        }
    }

    fclose($out);
    exit;
}

// --- Importación CSV ----------------------------------------------------
$importReport = null;
if ($action === 'import' && $user->hasRight('factymx', 'config', 'write')) {
    $file = $_FILES['csvfile']['tmp_name'] ?? '';
    $kind = GETPOST('kind', 'aZ09');

    if ($file === '' || !is_uploaded_file($file)) {
        setEventMessages('No se recibió ningún archivo.', null, 'errors');
    } else {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $updated = 0;
        $skipped = 0;
        $errors  = array();
        $line    = 1;

        $db->begin();
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (!$row || count($row) < 2) {
                continue;
            }

            $rowid = (int) preg_replace('/[^0-9]/', '', (string) $row[0]);
            if ($rowid <= 0) {
                $errors[] = 'Línea ' . $line . ': id inválido.';
                continue;
            }

            if ($kind === 'products') {
                $clave  = trim((string) ($row[3] ?? ''));
                $unidad = trim((string) ($row[4] ?? ''));
                if ($clave === '' && $unidad === '') {
                    $skipped++;
                    continue;
                }
                // Se valida el formato aquí: una clave de 7 dígitos entra sin
                // problema en la base y sólo revienta al timbrar, que es
                // demasiado tarde y ya con un cliente esperando.
                if ($clave !== '' && !preg_match('/^\d{8}$/', $clave)) {
                    $errors[] = 'Línea ' . $line . ': la clave "' . $clave . '" no tiene 8 dígitos.';
                    continue;
                }
                $updated += factymxSetExtrafield($db, 'product', $rowid, array(
                    'factymx_claveprodserv' => $clave,
                    'factymx_claveunidad'   => $unidad,
                )) ? 1 : 0;
            } else {
                $regimen = trim((string) ($row[4] ?? ''));
                if ($regimen === '') {
                    $skipped++;
                    continue;
                }
                $updated += factymxSetExtrafield($db, 'societe', $rowid, array(
                    'factymx_regimenfiscal' => $regimen,
                )) ? 1 : 0;
            }
        }
        fclose($handle);

        if ($errors) {
            // Todo o nada: una importación a medias deja al usuario sin saber
            // qué quedó aplicado y qué no.
            $db->rollback();
        } else {
            $db->commit();
        }

        $importReport = array('updated' => $updated, 'skipped' => $skipped, 'errors' => $errors);
    }
}

/**
 * Escribe extrafields directamente. Se hace por SQL y no cargando el objeto
 * completo porque una importación puede tocar miles de filas y cargar cada
 * Product/Societe entero multiplicaría el tiempo sin ganar nada.
 */
function factymxSetExtrafield($db, string $table, int $rowid, array $values): bool
{
    $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . $table . '_extrafields WHERE fk_object = ' . $rowid;
    $res = $db->query($sql);
    $exists = $res && $db->fetch_object($res);
    if ($res) {
        $db->free($res);
    }

    $sets = array();
    foreach ($values as $col => $val) {
        if ($val === '') {
            continue;
        }
        $sets[] = $col . " = '" . $db->escape($val) . "'";
    }
    if (!$sets) {
        return false;
    }

    if ($exists) {
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . $table . '_extrafields SET ' . implode(', ', $sets)
            . ' WHERE fk_object = ' . $rowid;
    } else {
        $cols = array_keys($values);
        $vals = array();
        foreach ($values as $v) {
            $vals[] = "'" . $db->escape($v) . "'";
        }
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . $table . '_extrafields (fk_object, ' . implode(', ', $cols) . ') '
            . 'VALUES (' . $rowid . ', ' . implode(', ', $vals) . ')';
    }

    return (bool) $db->query($sql);
}

// ------------------------------------------------------------------ vista
llxHeader('', 'Facty — Qué falta');
print load_fiche_titre('Facty — Configuración', '', 'factymx@factymx');
print dol_get_fiche_head(factymxAdminPrepareHead(), 'preflight', 'Facty', -1, 'factymx@factymx');
print factymxEnvBanner();

if ($importReport !== null) {
    if ($importReport['errors']) {
        setEventMessages(
            'No se importó nada. Corrige estos problemas y vuelve a subir el archivo:',
            $importReport['errors'],
            'errors'
        );
    } else {
        setEventMessages(
            'Importación aplicada: ' . $importReport['updated'] . ' actualizados, '
            . $importReport['skipped'] . ' sin cambios.',
            null,
            'mesgs'
        );
    }
}

$productsMissing = factymxProductsMissing($db, $entity);
$clientsMissing  = factymxClientsMissing($db, $entity);

print '<span class="opacitymedium">Esta pantalla revisa lo que el SAT exige y todavía no está capturado. '
    . 'Mientras haya faltantes, esas facturas no se van a poder timbrar.</span><br><br>';

// --- Productos
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="4">'
    . 'Productos sin datos del SAT (' . count($productsMissing) . ')</td></tr>';

if (!$productsMissing) {
    print '<tr class="oddeven"><td colspan="4"><span class="ok">Todos los productos a la venta tienen clave y unidad.</span></td></tr>';
} else {
    print '<tr class="liste_titre"><td>Referencia</td><td>Descripción</td><td>Clave prod/serv</td><td>Unidad</td></tr>';
    foreach ($productsMissing as $r) {
        print '<tr class="oddeven">';
        print '<td><a href="' . dol_buildpath('/factymx/product/sat.php', 1) . '?id=' . ((int) $r->rowid) . '">'
            . dol_escape_htmltag((string) $r->ref) . '</a></td>';
        print '<td>' . dol_escape_htmltag((string) $r->label) . '</td>';
        print '<td>' . ($r->clave ? dol_escape_htmltag((string) $r->clave) : '<span class="error">falta</span>') . '</td>';
        print '<td>' . ($r->unidad ? dol_escape_htmltag((string) $r->unidad) : '<span class="error">falta</span>') . '</td>';
        print '</tr>';
    }
}
print '</table>';
print '<div class="center"><br><a class="button" href="' . $_SERVER['PHP_SELF']
    . '?action=export_products&token=' . newToken() . '">Descargar CSV de productos</a></div><br>';

// --- Clientes
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="4">'
    . 'Clientes sin datos fiscales (' . count($clientsMissing) . ')</td></tr>';

if (!$clientsMissing) {
    print '<tr class="oddeven"><td colspan="4"><span class="ok">Todos los clientes tienen RFC, código postal y régimen fiscal.</span></td></tr>';
} else {
    print '<tr class="liste_titre"><td>Nombre</td><td>RFC</td><td>CP</td><td>Régimen</td></tr>';
    foreach ($clientsMissing as $r) {
        print '<tr class="oddeven">';
        print '<td><a href="' . dol_buildpath('/factymx/societe/fiscal.php', 1) . '?socid=' . ((int) $r->rowid) . '">'
            . dol_escape_htmltag((string) $r->nom) . '</a></td>';
        print '<td>' . ($r->idprof1 ? dol_escape_htmltag((string) $r->idprof1) : '<span class="error">falta</span>') . '</td>';
        print '<td>' . ($r->zip ? dol_escape_htmltag((string) $r->zip) : '<span class="error">falta</span>') . '</td>';
        print '<td>' . ($r->regimen ? dol_escape_htmltag((string) $r->regimen) : '<span class="error">falta</span>') . '</td>';
        print '</tr>';
    }
}
print '</table>';
print '<div class="center"><br><a class="button" href="' . $_SERVER['PHP_SELF']
    . '?action=export_clients&token=' . newToken() . '">Descargar CSV de clientes</a></div><br>';

// --- Importación
if ($user->hasRight('factymx', 'config', 'write')) {
    print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Subir el CSV corregido</td></tr>';
    print '<tr class="oddeven"><td class="titlefield">Archivo</td><td>';
    print '<form method="POST" enctype="multipart/form-data" action="' . $_SERVER['PHP_SELF'] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="import">';
    print '<input type="file" name="csvfile" accept=".csv,text/csv"> ';
    print '<select name="kind"><option value="products">Productos</option><option value="clients">Clientes</option></select> ';
    print '<input type="submit" class="button" value="Importar">';
    print '</form>';
    print '<span class="opacitymedium">Mismo formato que el CSV descargado. '
        . 'Si alguna línea tiene un error, no se aplica nada: se corrige y se vuelve a subir.</span>';
    print '</td></tr></table>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
