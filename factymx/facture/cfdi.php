<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    facture/cfdi.php
 * \ingroup factymx
 * \brief   Pestaña CFDI de la factura: revisar, timbrar y ver el resultado.
 *
 * La pantalla enseña PRIMERO todo lo que falta y sólo entonces ofrece el botón.
 * Timbrar gasta un timbre y emite un comprobante fiscal: descubrir los
 * problemas de uno en uno a base de intentos fallidos es caro y desmoralizante.
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once __DIR__ . '/../lib/factymx.lib.php';
require_once __DIR__ . '/../class/FactyConfig.class.php';
require_once __DIR__ . '/../class/FactyCatalog.class.php';
require_once __DIR__ . '/../class/FactyCfdi.class.php';
require_once __DIR__ . '/../class/FactyStamp.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('bills', 'factymx@factymx'));

$facid  = GETPOSTINT('facid') ?: GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!$facid || !$user->hasRight('factymx', 'cfdi', 'read')) {
    accessforbidden();
}

$object = new Facture($db);
if ($object->fetch($facid) <= 0) {
    accessforbidden();
}
$object->fetch_thirdparty();
$object->fetch_optionals();
foreach ($object->lines as $line) {
    if (method_exists($line, 'fetch_optionals')) {
        $line->fetch_optionals();
    }
}

$catalog  = new FactyCatalog($db);
$isEgreso = ((int) $object->type === Facture::TYPE_CREDIT_NOTE);
$cfdi     = FactyCfdi::fetchByFacture($db, (int) $object->id);

$stamp = new FactyStamp($db);

if ($action === 'stamp' && $user->hasRight('factymx', 'cfdi', 'create')) {
    $opts = array(
        'usoCfdi'    => GETPOST('usocfdi', 'alpha'),
        'metodoPago' => GETPOST('metodopago', 'alpha'),
        'formaPago'  => GETPOST('formapago', 'alpha'),
    );

    $uuidRel = trim((string) GETPOST('uuid_relacionado', 'alpha'));
    if ($uuidRel !== '') {
        $opts['cfdiRelacionados'] = array(
            'tipoRelacion' => GETPOST('tiporelacion', 'alpha') ?: '01',
            'uuids'        => array($uuidRel),
        );
    }

    $result = $stamp->stamp($object, $opts);
    if ($result !== null) {
        setEventMessages('CFDI timbrado. Folio fiscal: ' . dol_escape_htmltag((string) $result->uuid), null, 'mesgs');
    } else {
        if ($stamp->problems) {
            setEventMessages('No se pudo timbrar:', $stamp->problems, 'errors');
        }
        if ($stamp->error !== '') {
            setEventMessages($stamp->error, null, 'errors');
        }
    }
    $cfdi = FactyCfdi::fetchByFacture($db, (int) $object->id);
}

llxHeader('', 'CFDI');

$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'factymx', $langs->trans('Bill'), -1, 'bill');
dol_banner_tab($object, 'ref', '', 1, 'ref');
print '<div class="fichecenter">';

print factymxEnvBanner();

// ---------------------------------------------------------------- timbrado
if ($cfdi !== null && $cfdi->status === FactyCfdi::STATUS_STAMPED) {
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">Estado</td><td><span class="factymx-status-stamped">Timbrado</span> '
        . factymxEnvBadge($cfdi->env) . '</td></tr>';
    print '<tr><td>Folio fiscal (UUID)</td><td><span class="factymx-request-id">'
        . dol_escape_htmltag((string) $cfdi->uuid) . '</span></td></tr>';
    print '<tr><td>Serie y folio</td><td>' . dol_escape_htmltag(trim($cfdi->serie . ' ' . $cfdi->folio)) . '</td></tr>';
    print '<tr><td>Fecha de timbrado</td><td>' . dol_escape_htmltag((string) $cfdi->stamped_at) . '</td></tr>';
    print '<tr><td>Total</td><td>' . price((float) $cfdi->total) . ' ' . dol_escape_htmltag((string) $cfdi->moneda) . '</td></tr>';
    print '</table>';

    print '<div class="center"><br><span class="opacitymedium">'
        . 'La descarga del XML y del PDF, la cancelación y la consulta de estatus llegan en la siguiente versión.'
        . '</span></div>';
} elseif ($cfdi !== null && $cfdi->status === FactyCfdi::STATUS_PENDING) {
    // Ni "listo" ni "falló": el módulo no sabe todavía. Decirlo tal cual es lo
    // único honesto, y evita que alguien vuelva a darle al botón.
    print '<div class="warning">';
    print '<strong>Timbrado en proceso.</strong> No se pudo confirmar el resultado con Facty. '
        . 'El módulo va a verificar automáticamente en unos minutos si el CFDI se emitió. '
        . '<strong>No vuelvas a timbrar esta factura</strong> mientras tanto: si el timbrado sí llegó, '
        . 'un segundo intento emitiría un comprobante duplicado.';
    print '</div>';
} else {
    if ($cfdi !== null && $cfdi->status === FactyCfdi::STATUS_FAILED) {
        print '<div class="error"><strong>El último intento falló:</strong> '
            . dol_escape_htmltag((string) $cfdi->last_error);
        if ($cfdi->facty_request_id) {
            print ' <span class="factymx-request-id">(referencia ' . dol_escape_htmltag($cfdi->facty_request_id) . ')</span>';
        }
        print '</div><br>';
    }

    // Revisión previa: sin llamadas a Facty y sin gastar nada.
    $ready = $stamp->precheck($object);

    if (!$ready) {
        print '<div class="warning"><strong>Antes de timbrar hay que resolver esto:</strong><ul>';
        foreach ($stamp->problems as $p) {
            print '<li>' . dol_escape_htmltag($p) . '</li>';
        }
        print '</ul></div><br>';
    }

    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?facid=' . ((int) $object->id) . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="stamp">';

    print '<table class="border centpercent">';

    print '<tr><td class="titlefield">Tipo de comprobante</td><td>'
        . ($isEgreso ? 'Egreso — nota de crédito' : 'Ingreso — factura') . '</td></tr>';

    print '<tr><td>Uso del CFDI</td><td>';
    $usoActual = (string) ($object->array_options['options_factymx_usocfdi'] ?? '');
    if ($usoActual === '') {
        $usoActual = getDolGlobalString('FACTYMX_DEFAULT_USOCFDI');
    }
    print $catalog->selectHtml('UsoCfdi', 'usocfdi', $usoActual, false);
    print '</td></tr>';

    print '<tr><td>Método de pago</td><td>';
    $metodoActual = (string) ($object->array_options['options_factymx_metodopago'] ?? '');
    if ($metodoActual === '') {
        $metodoActual = getDolGlobalString('FACTYMX_DEFAULT_METODOPAGO') ?: 'PUE';
    }
    print '<select name="metodopago" class="flat">';
    print '<option value="PUE"' . ($metodoActual === 'PUE' ? ' selected' : '') . '>PUE — una sola exhibición</option>';
    print '<option value="PPD"' . ($metodoActual === 'PPD' ? ' selected' : '') . '>PPD — parcialidades o diferido</option>';
    print '</select>';
    print ' <span class="opacitymedium">Con PPD, la forma de pago se fuerza a 99 y después hay que emitir complementos.</span>';
    print '</td></tr>';

    print '<tr><td>Forma de pago</td><td>';
    // Se preselecciona la clave mapeada al modo de pago de la factura, para que
    // el caso normal no exija ninguna decisión y el usuario sólo intervenga
    // cuando el mapeo falte o no aplique.
    $formaMapeada = '';
    if (!empty($object->mode_reglement_code)) {
        $formaMapeada = getDolGlobalString(
            'FACTYMX_FORMAPAGO_' . strtoupper(dol_sanitizeFileName((string) $object->mode_reglement_code))
        );
    }
    print $catalog->selectHtml('FormaPago', 'formapago', $formaMapeada, true);
    if ($formaMapeada === '' && !empty($object->mode_reglement_code)) {
        print ' <span class="warning">El modo de pago "' . dol_escape_htmltag((string) $object->mode_reglement_code)
            . '" no está mapeado a una clave del SAT. Elige una aquí o configúralo en el módulo.</span>';
    } else {
        print ' <span class="opacitymedium">Tomada del modo de pago de la factura.</span>';
    }
    print '</td></tr>';

    // Nota de crédito: la relación con el comprobante original es obligatoria.
    if ($isEgreso) {
        print '<tr><td>CFDI que corrige</td><td>';

        // Si la factura de origen se timbró con este módulo, ya tenemos su
        // UUID: se propone en vez de pedirle al usuario que lo busque.
        $sugerido = '';
        if (!empty($object->fk_facture_source)) {
            $origen = FactyCfdi::fetchByFacture($db, (int) $object->fk_facture_source);
            if ($origen !== null && $origen->uuid) {
                $sugerido = (string) $origen->uuid;
            }
        }

        print '<input type="text" name="uuid_relacionado" size="40" value="' . dol_escape_htmltag($sugerido) . '" '
            . 'placeholder="folio fiscal de la factura original">';
        if ($sugerido !== '') {
            print ' <span class="opacitymedium">(tomado de la factura de origen)</span>';
        }
        print '<br><select name="tiporelacion" class="flat">';
        print '<option value="01">01 — Nota de crédito de los documentos relacionados</option>';
        print '<option value="03">03 — Devolución de mercancía</option>';
        print '<option value="04">04 — Sustitución de los CFDI previos</option>';
        print '</select>';
        print '<br><span class="opacitymedium">El SAT exige relacionar la nota de crédito con el comprobante que corrige.</span>';
        print '</td></tr>';
    }

    print '</table>';

    print '<div class="center"><br>';
    if (!$user->hasRight('factymx', 'cfdi', 'create')) {
        print '<span class="opacitymedium">No tienes permiso para timbrar.</span>';
    } elseif (!$ready) {
        print '<input type="submit" class="button" value="Timbrar" disabled> '
            . '<span class="opacitymedium">Resuelve los puntos de arriba para habilitar el timbrado.</span>';
    } else {
        print '<input type="submit" class="button" value="Timbrar con Facty">';
        if (FactyConfig::isTest()) {
            print '<br><span class="opacitymedium">Estás en el ambiente de pruebas: el comprobante no tendrá validez fiscal.</span>';
        }
    }
    print '</div>';
    print '</form>';
}

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
