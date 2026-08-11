<?php
/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    admin/defaults.php
 * \ingroup factymx
 * \brief   Valores por omisión al timbrar y mapeo de formas de pago.
 *
 * El mapeo de formas de pago vive aquí porque Dolibarr y el SAT no hablan el
 * mismo idioma: `llx_c_paiement` trae los medios de pago de Dolibarr y el SAT
 * exige una clave de su catálogo c_FormaPago. Sin esta tabla habría que
 * adivinar, y adivinar mal significa un CFDI rechazado o —peor— aceptado con
 * una forma de pago incorrecta.
 *
 * El mapeo de cuentas bancarias es POR AMBIENTE: el `accountId` de Facty es un
 * id de su base de datos, y producción y pruebas son bases distintas.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/factymx.lib.php';
require_once __DIR__.'/../class/FactyConfig.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('admin', 'factymx@factymx'));

if (!$user->admin && !$user->hasRight('factymx', 'config', 'write')) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$env    = FactyConfig::env();

if ($action === 'save' && $user->hasRight('factymx', 'config', 'write')) {
    dolibarr_set_const($db, 'FACTYMX_DEFAULT_SERIE', GETPOST('serie', 'alpha'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'FACTYMX_DEFAULT_USOCFDI', GETPOST('usocfdi', 'alpha'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'FACTYMX_DEFAULT_METODOPAGO', GETPOST('metodopago', 'alpha') === 'PPD' ? 'PPD' : 'PUE', 'chaine', 0, '', $conf->entity);

    // Formas de pago: una constante por medio de pago de Dolibarr.
    $formas = GETPOST('forma', 'array');
    if (is_array($formas)) {
        foreach ($formas as $codePaiement => $claveSat) {
            $clave = preg_replace('/[^0-9]/', '', (string) $claveSat);
            dolibarr_set_const(
                $db,
                'FACTYMX_FORMAPAGO_'.strtoupper(dol_sanitizeFileName((string) $codePaiement)),
                $clave,
                'chaine',
                0,
                '',
                $conf->entity
            );
        }
    }

    // Cuentas bancarias → accountId de Facty, con el ambiente en el nombre de
    // la constante para que las dos configuraciones coexistan.
    $cuentas = GETPOST('cuenta', 'array');
    if (is_array($cuentas)) {
        foreach ($cuentas as $bankId => $factyAccountId) {
            dolibarr_set_const(
                $db,
                'FACTYMX_ACCOUNT_'.FactyConfig::suffix($env).'_'.((int) $bankId),
                trim((string) $factyAccountId),
                'chaine',
                0,
                '',
                $conf->entity
            );
        }
    }

    setEventMessages('Valores guardados.', null, 'mesgs');
}

llxHeader('', 'Facty — Valores por omisión');
print load_fiche_titre('Facty — Configuración', '', 'factymx@factymx');
print dol_get_fiche_head(factymxAdminPrepareHead(), 'defaults', 'Facty', -1, 'factymx@factymx');
print factymxEnvBanner();

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

// --- Valores del comprobante
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Al timbrar</td></tr>';

print '<tr class="oddeven"><td class="titlefield">Serie</td><td>';
print '<input type="text" name="serie" maxlength="25" value="'
    .dol_escape_htmltag(getDolGlobalString('FACTYMX_DEFAULT_SERIE')).'">';
print ' <span class="opacitymedium">Si se deja vacío, Facty usa la serie configurada en la organización.</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>Uso de CFDI por omisión</td><td>';
print '<input type="text" name="usocfdi" maxlength="4" value="'
    .dol_escape_htmltag(getDolGlobalString('FACTYMX_DEFAULT_USOCFDI')).'">';
print ' <span class="opacitymedium">Clave del catálogo c_UsoCFDI (por ejemplo G03). Se puede cambiar factura por factura.</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>Método de pago</td><td>';
$metodo = getDolGlobalString('FACTYMX_DEFAULT_METODOPAGO') === 'PPD' ? 'PPD' : 'PUE';
print '<select name="metodopago">';
print '<option value="PUE"'.($metodo === 'PUE' ? ' selected' : '').'>PUE — Pago en una sola exhibición</option>';
print '<option value="PPD"'.($metodo === 'PPD' ? ' selected' : '').'>PPD — Pago en parcialidades o diferido</option>';
print '</select>';
print ' <span class="opacitymedium">Con PPD, el SAT obliga a emitir después un complemento de pago por cada abono.</span>';
print '</td></tr>';
print '</table><br>';

// --- Formas de pago
print '<table class="noborder centpercent"><tr class="liste_titre">'
    .'<td colspan="2">Formas de pago: Dolibarr → SAT (c_FormaPago)</td></tr>';

$sql = 'SELECT id, code, libelle FROM '.MAIN_DB_PREFIX.'c_paiement WHERE active = 1 ORDER BY code';
$resq = $db->query($sql);
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $const = 'FACTYMX_FORMAPAGO_'.strtoupper(dol_sanitizeFileName((string) $row->code));
        $val   = getDolGlobalString($const);

        print '<tr class="oddeven"><td class="titlefield">'
            .dol_escape_htmltag($row->libelle.' ('.$row->code.')').'</td><td>';
        print '<input type="text" name="forma['.dol_escape_htmltag((string) $row->code).']" '
            .'maxlength="2" size="4" value="'.dol_escape_htmltag($val).'" placeholder="03">';
        if ($val === '') {
            // Se avisa aquí y no al momento de timbrar: descubrir un mapeo
            // faltante con la factura ya validada es una interrupción cara.
            print ' <span class="warning">Sin mapear — las facturas con esta forma de pago no se podrán timbrar.</span>';
        }
        print '</td></tr>';
    }
    $db->free($resq);
}
print '</table>';
print '<span class="opacitymedium">Claves frecuentes: 01 efectivo · 02 cheque · 03 transferencia · 04 tarjeta de crédito · '
    .'28 tarjeta de débito · 99 por definir (obligatoria en facturas PPD).</span><br><br>';

// --- Cuentas bancarias
print '<table class="noborder centpercent"><tr class="liste_titre">'
    .'<td colspan="2">Cuentas bancarias → Facty ('.FactyConfig::label($env).')</td></tr>';

$sql = 'SELECT rowid, label, number FROM '.MAIN_DB_PREFIX.'bank_account
        WHERE entity = '.((int) $conf->entity).' AND clos = 0 ORDER BY label';
$resq = $db->query($sql);
$nacc = 0;
if ($resq) {
    while ($row = $db->fetch_object($resq)) {
        $nacc++;
        $const = 'FACTYMX_ACCOUNT_'.FactyConfig::suffix($env).'_'.((int) $row->rowid);
        print '<tr class="oddeven"><td class="titlefield">'.dol_escape_htmltag($row->label).'</td><td>';
        print '<input type="text" class="minwidth200" name="cuenta['.((int) $row->rowid).']" value="'
            .dol_escape_htmltag(getDolGlobalString($const)).'" placeholder="id de la cuenta en Facty">';
        print '</td></tr>';
    }
    $db->free($resq);
}
if ($nacc === 0) {
    print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">No hay cuentas bancarias abiertas.</span></td></tr>';
}
print '</table>';
print '<span class="opacitymedium">El complemento de pago exige decir a qué cuenta entró el dinero. '
    .'Este mapeo es por ambiente: el identificador de Facty en pruebas no existe en producción.</span>';

print '<div class="center"><br><input type="submit" class="button" value="Guardar"></div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
