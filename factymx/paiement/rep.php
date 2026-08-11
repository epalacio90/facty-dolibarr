<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    paiement/rep.php
 * \ingroup factymx
 * \brief   Complemento de pago (REP) de un pago de Dolibarr.
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

require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/bank.lib.php';
require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyConfig.class.php';
require_once __DIR__ . '/../class/FactyCatalog.class.php';
require_once __DIR__ . '/../class/FactyPayment.class.php';
require_once __DIR__ . '/../class/FactyRep.class.php';
require_once __DIR__ . '/../class/FactyCancel.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('bills', 'banks', 'factymx@factymx'));

$id     = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!$id || !$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$object = new Paiement($db);
if ($object->fetch($id) <= 0) {
    accessforbidden();
}

$rep  = new FactyRep($db);
$rec  = FactyPayment::fetchByPaiement($db, (int) $object->id);

if ($action === 'stamp' && $user->hasRight('factymx', 'rep', 'create')) {
    $opts = array(
        'moneda'     => GETPOST('moneda', 'alpha') ?: 'MXN',
        'tipoCambio' => GETPOST('tipocambio', 'alpha'),
    );

    $result = $rep->stamp($object, $opts);
    if ($result !== null) {
        setEventMessages(
            'Complemento de pago timbrado. Folio fiscal: ' . dol_escape_htmltag((string) $result->uuid),
            null,
            'mesgs'
        );
    } else {
        if ($rep->problems) {
            setEventMessages('No se pudo timbrar el complemento:', $rep->problems, 'errors');
        }
        if ($rep->error !== '') {
            setEventMessages($rep->error, null, 'errors');
        }
    }
    $rec = FactyPayment::fetchByPaiement($db, (int) $object->id);
}

if ($action === 'cancel' && $rec !== null && $user->hasRight('factymx', 'rep', 'cancel')) {
    $ok = $rep->cancel($rec, (string) GETPOST('motivo', 'alpha'), (string) GETPOST('folio_sustitucion', 'alpha'));
    setEventMessages(
        $ok ? 'Complemento de pago cancelado.' : $rep->error,
        null,
        $ok ? 'mesgs' : 'errors'
    );
    $rec = FactyPayment::fetchByPaiement($db, (int) $object->id);
}

llxHeader('', 'Complemento de pago');

$head = payment_prepare_head($object);
print dol_get_fiche_head($head, 'factymxrep', $langs->trans('Payment'), -1, 'payment');
print '<div class="fichecenter">';

print factymxEnvBanner();

print '<table class="border centpercent">';
print '<tr><td class="titlefield">Pago</td><td>' . dol_escape_htmltag((string) $object->ref) . '</td></tr>';
print '<tr><td>Fecha</td><td>' . dol_print_date($object->datepaye ?: $object->date, 'day') . '</td></tr>';
print '<tr><td>Importe</td><td>' . price((float) $object->amount) . '</td></tr>';
print '</table><br>';

// ------------------------------------------------------------- ya timbrado
if ($rec !== null && in_array($rec->status, array(FactyPayment::STATUS_STAMPED, FactyPayment::STATUS_CANCELLED), true)) {
    $cancelado = ($rec->status === FactyPayment::STATUS_CANCELLED);

    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">Estado</td><td>';
    print $cancelado
        ? '<span class="factymx-status-cancelled">Cancelado</span>'
        : '<span class="factymx-status-stamped">Timbrado</span>';
    print ' ' . factymxEnvBadge($rec->env) . '</td></tr>';
    print '<tr><td>Folio fiscal (UUID)</td><td><span class="factymx-request-id">'
        . dol_escape_htmltag((string) $rec->uuid) . '</span></td></tr>';
    print '<tr><td>Fecha de timbrado</td><td>' . dol_escape_htmltag((string) $rec->stamped_at) . '</td></tr>';
    if ($cancelado) {
        print '<tr><td>Cancelado el</td><td>' . dol_escape_htmltag((string) $rec->cancelled_at) . '</td></tr>';
    }
    print '</table>';

    if (!$cancelado && $user->hasRight('factymx', 'rep', 'cancel')) {
        print '<br><table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'
            . 'Cancelar el complemento</td></tr>';
        print '<tr class="oddeven"><td colspan="2"><div class="warning">';
        // Esto no es sólo anular un comprobante: del lado de Facty el pago
        // entero queda sin efecto. Decirlo antes, no después.
        print 'Cancelar el complemento <strong>anula el pago completo</strong>: la factura vuelve a quedar '
            . 'con saldo pendiente y se revierte el abono a la cuenta bancaria. Consume un timbre y no se '
            . 'puede deshacer. Si el dinero sí se recibió, tendrás que volver a registrar el pago y timbrar '
            . 'un complemento nuevo.';
        print '</div></td></tr>';

        print '<tr class="oddeven"><td class="titlefield">Motivo</td><td>';
        print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id) . '">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="cancel">';
        print '<select name="motivo" class="flat">';
        foreach (FactyCancel::MOTIVOS as $code => $label) {
            print '<option value="' . $code . '">' . $code . ' — ' . dol_escape_htmltag($label) . '</option>';
        }
        print '</select></td></tr>';

        print '<tr class="oddeven"><td>Folio que lo sustituye</td><td>';
        print '<input type="text" name="folio_sustitucion" size="40" placeholder="sólo para el motivo 01">';
        print '</td></tr>';

        print '<tr class="oddeven"><td></td><td>';
        print '<input type="submit" class="button butActionDelete" value="Cancelar el complemento" '
            . 'onclick="return confirm(\'Esto anula el pago completo y consume un timbre. ¿Continuar?\');">';
        print '</form></td></tr></table>';
    }
} elseif ($rec !== null && $rec->status === FactyPayment::STATUS_PENDING) {
    print '<div class="warning"><strong>Timbrado en proceso.</strong> No se pudo confirmar el resultado con Facty. '
        . 'El módulo verificará automáticamente en unos minutos. '
        . '<strong>No lo vuelvas a timbrar</strong> mientras tanto.</div>';
} else {
    if ($rec !== null && $rec->status === FactyPayment::STATUS_FAILED) {
        print '<div class="error"><strong>El último intento falló:</strong> '
            . dol_escape_htmltag((string) $rec->last_error) . '</div><br>';
    }

    // Documentos relacionados: se calculan y se muestran ANTES de timbrar, con
    // parcialidad y saldos a la vista. Son los números que van al SAT y tienen
    // que cuadrar entre sí; enseñarlos permite detectar un error antes de gastar
    // el timbre, no después.
    $docs = $rep->buildDocuments($object);

    print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="5">'
        . 'Documentos relacionados</td></tr>';

    if ($docs) {
        print '<tr class="liste_titre"><td>Factura</td><td>Folio fiscal</td><td>Parcialidad</td>'
            . '<td class="right">Saldo anterior</td><td class="right">Pagado / insoluto</td></tr>';
        foreach ($docs as $d) {
            print '<tr class="oddeven">';
            print '<td>' . dol_escape_htmltag((string) $d['_ref']) . '</td>';
            print '<td><span class="factymx-request-id">' . dol_escape_htmltag(substr((string) $d['_uuid'], 0, 13)) . '…</span></td>';
            print '<td>' . ((int) $d['numParcialidad']) . '</td>';
            print '<td class="right">' . price((float) $d['importeSaldoAnterior']) . '</td>';
            print '<td class="right">' . price((float) $d['importePagado']) . ' / '
                . price((float) $d['importeSaldoInsoluto']) . '</td>';
            print '</tr>';
        }
    } else {
        print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'
            . 'No hay documentos que se puedan relacionar.</span></td></tr>';
    }
    print '</table>';

    if ($rep->problems) {
        print '<br><div class="warning"><strong>Antes de timbrar hay que resolver esto:</strong><ul>';
        foreach ($rep->problems as $p) {
            print '<li>' . dol_escape_htmltag($p) . '</li>';
        }
        print '</ul></div>';
    }

    print '<br><form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id) . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="stamp">';

    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">Moneda del pago</td><td>';
    // La moneda se pregunta aquí y no se hereda en silencio: el REP la exige y
    // el pago de Dolibarr no siempre la trae explícita.
    $catalog = new FactyCatalog($db);
    print $catalog->selectHtml('Moneda', 'moneda', 'MXN', false);
    print '</td></tr>';
    print '<tr><td>Tipo de cambio</td><td>';
    print '<input type="text" name="tipocambio" size="10" placeholder="sólo si no es MXN">';
    print '</td></tr>';
    print '</table>';

    print '<div class="center"><br>';
    if (!$user->hasRight('factymx', 'rep', 'create')) {
        print '<span class="opacitymedium">No tienes permiso para timbrar complementos de pago.</span>';
    } elseif ($rep->problems || !$docs) {
        print '<input type="submit" class="button" value="Timbrar complemento" disabled>';
    } else {
        print '<input type="submit" class="button" value="Timbrar complemento de pago">';
    }
    print '</div></form>';
}

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
