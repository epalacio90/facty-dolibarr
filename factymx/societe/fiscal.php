<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    societe/fiscal.php
 * \ingroup factymx
 * \brief   Datos fiscales del receptor y sincronización con Facty.
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
require_once __DIR__ . '/../class/FactyCatalog.class.php';
require_once __DIR__ . '/../class/FactyClientSync.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('companies', 'factymx@factymx'));

$socid  = GETPOSTINT('socid') ?: GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!$socid) {
    accessforbidden();
}
if (!$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
    accessforbidden();
}

$catalog = new FactyCatalog($db);

if ($action === 'save' && $user->hasRight('societe', 'creer')) {
    $object->array_options['options_factymx_regimenfiscal'] = GETPOST('regimenfiscal', 'alpha');
    $object->array_options['options_factymx_usocfdi']       = GETPOST('usocfdi', 'alpha');

    if ($object->updateExtraField('factymx_regimenfiscal') > 0 && $object->updateExtraField('factymx_usocfdi') > 0) {
        setEventMessages('Datos fiscales guardados.', null, 'mesgs');
    } else {
        setEventMessages($object->error, $object->errors, 'errors');
    }
    $object->fetch($socid);
}

if ($action === 'sync' && $user->hasRight('factymx', 'cfdi', 'create')) {
    try {
        $sync = new FactyClientSync($db);
        $id   = $sync->ensure($object);
        setEventMessages('Cliente sincronizado con Facty (' . dol_escape_htmltag($id) . ').', null, 'mesgs');
    } catch (InvalidArgumentException $e) {
        // Falta un dato del receptor: es accionable por quien está viendo la
        // ficha, así que se dice aquí y no se disfraza de error técnico.
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

llxHeader('', 'Datos fiscales CFDI');

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'factymxfiscal', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($object, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print '<div class="fichecenter">';

print factymxEnvBanner();

// Estado de la sincronización, por ambiente.
$sql = 'SELECT facty_client_id, synced_at FROM ' . MAIN_DB_PREFIX . "factymx_client_map
        WHERE fk_soc = " . ((int) $object->id) . " AND entity = " . ((int) $conf->entity) . "
          AND env = '" . $db->escape(FactyConfig::env()) . "'";
$mapRow = null;
$resq = $db->query($sql);
if ($resq) {
    $mapRow = $db->fetch_object($resq);
    $db->free($resq);
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?socid=' . ((int) $object->id) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<table class="border centpercent">';

print '<tr><td class="titlefield">RFC</td><td>';
$rfc = (string) ($object->idprof1 ?: $object->tva_intra);
if ($rfc === '') {
    print '<span class="error">Sin RFC</span> — captúralo en la ficha del tercero; sin él no se puede timbrar.';
} else {
    print dol_escape_htmltag(strtoupper($rfc));
}
print '</td></tr>';

print '<tr><td>Código postal</td><td>';
if ((string) $object->zip === '') {
    print '<span class="error">Sin código postal</span> — el CFDI 4.0 exige el domicilio fiscal del receptor.';
} else {
    print dol_escape_htmltag((string) $object->zip);
}
print '</td></tr>';

print '<tr><td>Régimen fiscal</td><td>';
print $catalog->selectHtml(
    'RegimenFiscal',
    'regimenfiscal',
    (string) ($object->array_options['options_factymx_regimenfiscal'] ?? '')
);
print '</td></tr>';

print '<tr><td>Uso del CFDI por omisión</td><td>';
print $catalog->selectHtml(
    'UsoCfdi',
    'usocfdi',
    (string) ($object->array_options['options_factymx_usocfdi'] ?? '')
);
print ' <span class="opacitymedium">Se puede cambiar en cada factura.</span>';
print '</td></tr>';

print '<tr><td>En Facty (' . FactyConfig::label() . ')</td><td>';
if ($mapRow) {
    print '<span class="ok">Sincronizado</span> <span class="opacitymedium">'
        . dol_escape_htmltag((string) $mapRow->facty_client_id)
        . ' · ' . dol_escape_htmltag((string) $mapRow->synced_at) . '</span>';
} else {
    print '<span class="opacitymedium">Todavía no sincronizado en este ambiente. '
        . 'Se hará solo al timbrar la primera factura.</span>';
}
print '</td></tr>';

print '</table>';

print '<div class="center"><br>';
if ($user->hasRight('societe', 'creer')) {
    print '<input type="submit" class="button" value="Guardar">';
}
if ($user->hasRight('factymx', 'cfdi', 'create')) {
    print ' <a class="button button-save" href="' . $_SERVER['PHP_SELF'] . '?socid=' . ((int) $object->id)
        . '&action=sync&token=' . newToken() . '">Sincronizar con Facty</a>';
}
print '</div>';
print '</form>';

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
