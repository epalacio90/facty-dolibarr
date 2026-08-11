<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    product/sat.php
 * \ingroup factymx
 * \brief   Claves del SAT del producto y sincronización con Facty.
 *
 * La clave de producto/servicio no se elige de un desplegable: el catálogo tiene
 * ~50 mil entradas y bajarlo completo a cada instalación es exactamente lo que
 * este diseño evita. Se busca contra Facty y se elige de los resultados.
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

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyCatalog.class.php';
require_once __DIR__ . '/../class/FactyProductSync.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('products', 'factymx@factymx'));

$id     = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$search = GETPOST('search_clave', 'alpha');

if (!$id || !$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$object = new Product($db);
if ($object->fetch($id) <= 0) {
    accessforbidden();
}

$catalog = new FactyCatalog($db);

if ($action === 'save' && $user->hasRight('produit', 'creer')) {
    $object->array_options['options_factymx_claveprodserv']    = GETPOST('claveprodserv', 'alpha');
    $object->array_options['options_factymx_claveunidad']      = GETPOST('claveunidad', 'alpha');
    $object->array_options['options_factymx_noidentificacion'] = GETPOST('noidentificacion', 'alpha');
    $object->array_options['options_factymx_objetoimp']        = GETPOST('objetoimp', 'alpha');

    $ok = true;
    foreach (array('factymx_claveprodserv', 'factymx_claveunidad', 'factymx_noidentificacion', 'factymx_objetoimp') as $f) {
        if ($object->updateExtraField($f) <= 0) {
            $ok = false;
        }
    }
    setEventMessages($ok ? 'Datos SAT guardados.' : $object->error, $ok ? null : $object->errors, $ok ? 'mesgs' : 'errors');
    $object->fetch($id);
}

if ($action === 'sync' && $user->hasRight('factymx', 'cfdi', 'create')) {
    try {
        $sync = new FactyProductSync($db);
        $factyId = $sync->ensure($object);
        setEventMessages('Producto sincronizado con Facty (' . dol_escape_htmltag($factyId) . ').', null, 'mesgs');
    } catch (InvalidArgumentException $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    } catch (FactyApiException $e) {
        $msg = $e->userMessage();
        if ($e->requestId !== null) {
            $msg .= ' (referencia: ' . $e->requestId . ')';
        }
        setEventMessages($msg, null, 'errors');
    } catch (Exception $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
}

llxHeader('', 'Datos SAT');

$head = product_prepare_head($object);
print dol_get_fiche_head($head, 'factymxsat', $langs->trans('Product'), -1, 'product');
dol_banner_tab($object, 'ref', '', 1, 'ref');
print '<div class="fichecenter">';

print factymxEnvBanner();

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

$clave = (string) ($object->array_options['options_factymx_claveprodserv'] ?? '');

print '<table class="border centpercent">';

print '<tr><td class="titlefield">Clave producto/servicio</td><td>';
print '<input type="text" name="claveprodserv" size="12" value="' . dol_escape_htmltag($clave) . '">';
if ($clave === '') {
    print ' <span class="error">Obligatoria para timbrar.</span>';
}
print '</td></tr>';

// Buscador: el catálogo es demasiado grande para un desplegable, así que se
// consulta a Facty y se muestran los resultados para copiar la clave.
print '<tr><td>Buscar clave</td><td>';
print '<input type="text" name="search_clave" size="30" value="' . dol_escape_htmltag($search) . '" '
    . 'placeholder="por ejemplo: servicios de consultoría">';
print ' <input type="submit" class="button smallpaddingimp" name="dosearch" value="Buscar">';

if ($search !== '') {
    $hits = $catalog->search('ClaveProdServ', $search);
    if ($catalog->state === FactyCatalog::STATE_UNAVAILABLE) {
        print '<br><span class="warning">' . dol_escape_htmltag($catalog->stateMessage) . '</span>';
    } elseif (!$hits) {
        print '<br><span class="opacitymedium">Sin resultados para “' . dol_escape_htmltag($search) . '”.</span>';
    } else {
        print '<br><table class="noborder">';
        foreach ($hits as $code => $label) {
            print '<tr class="oddeven"><td><strong>' . dol_escape_htmltag((string) $code) . '</strong></td>'
                . '<td>' . dol_escape_htmltag($label) . '</td></tr>';
        }
        print '</table>';
        print '<span class="opacitymedium">Copia la clave que corresponda al campo de arriba.</span>';
    }
}
print '</td></tr>';

print '<tr><td>Clave de unidad</td><td>';
$unidad = (string) ($object->array_options['options_factymx_claveunidad'] ?? '');
print '<input type="text" name="claveunidad" size="6" value="' . dol_escape_htmltag($unidad) . '">';
if ($unidad === '') {
    print ' <span class="error">Obligatoria para timbrar.</span>';
}
print ' <span class="opacitymedium">Frecuentes: H87 pieza · E48 servicio · KGM kilogramo · ACT actividad.</span>';
print '</td></tr>';

print '<tr><td>No. de identificación</td><td>';
print '<input type="text" name="noidentificacion" size="20" value="'
    . dol_escape_htmltag((string) ($object->array_options['options_factymx_noidentificacion'] ?? '')) . '">';
print ' <span class="opacitymedium">Tu SKU o número de parte. Opcional.</span>';
print '</td></tr>';

print '<tr><td>Objeto de impuesto</td><td>';
print $catalog->selectHtml(
    'ObjetoImp',
    'objetoimp',
    (string) ($object->array_options['options_factymx_objetoimp'] ?? '')
);
print ' <span class="opacitymedium">Si se deja vacío, Facty lo deduce de los impuestos del concepto.</span>';
print '</td></tr>';

print '</table>';

print '<div class="center"><br>';
if ($user->hasRight('produit', 'creer')) {
    print '<input type="submit" class="button" value="Guardar">';
}
if ($user->hasRight('factymx', 'cfdi', 'create')) {
    print ' <a class="button button-save" href="' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id)
        . '&action=sync&token=' . newToken() . '">Sincronizar con Facty</a>';
}
print '</div>';
print '</form>';

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
