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
require_once __DIR__ . '/../class/FactyCancel.class.php';
require_once __DIR__ . '/../class/FactyArtifacts.class.php';

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

    // CFDI relacionados: el desplegable propone los timbrados de este cliente;
    // el campo de texto es la salida para folios emitidos con otra herramienta,
    // que el SAT acepta igual pero de los que aquí no hay registro.
    $uuidRel = trim((string) GETPOST('uuid_relacionado', 'alpha'));
    if ($uuidRel === '') {
        $uuidRel = trim((string) GETPOST('uuid_relacionado_sel', 'alpha'));
    }
    if ($uuidRel !== '') {
        $opts['cfdiRelacionados'] = array(
            'tipoRelacion' => GETPOST('tiporelacion', 'alpha') ?: '01',
            'uuids'        => array(strtoupper($uuidRel)),
        );
    }

    // Factura global: sustituye al receptor por el público en general y exige
    // el periodo que ampara.
    if (GETPOST('es_global', 'int')) {
        $opts['informacionGlobal'] = array(
            'periodicidad' => (string) GETPOST('periodicidad', 'alpha'),
            'meses'        => (string) GETPOST('meses', 'alpha'),
            'anio'         => (int) GETPOST('anio', 'int'),
        );
    }

    $result = $stamp->stamp($object, $opts);
    if ($result !== null) {
        setEventMessages('CFDI timbrado. Folio fiscal: ' . dol_escape_htmltag((string) $result->uuid), null, 'mesgs');

        // El XML y el PDF se bajan de una vez: el usuario acaba de emitir un
        // comprobante fiscal y lo siguiente que va a querer es mandárselo a su
        // cliente. Si la descarga falla no se deshace nada — el CFDI ya existe
        // y los archivos se pueden volver a pedir con el botón.
        try {
            $artifacts = new FactyArtifacts($db);
            $artifacts->fetchForInvoice($object, $result);
        } catch (Exception $e) {
            setEventMessages(
                'El CFDI se timbró correctamente, pero no se pudieron descargar los archivos: '
                . $e->getMessage() . ' Puedes intentarlo con el botón "Descargar de Facty".',
                null,
                'warnings'
            );
        }
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

$satStatus = null;

if ($action === 'fetchfiles' && $cfdi !== null && $user->hasRight('factymx', 'cfdi', 'read')) {
    try {
        $artifacts = new FactyArtifacts($db);
        $artifacts->fetchForInvoice($object, $cfdi, true);
        if ($cfdi->status === FactyCfdi::STATUS_CANCELLED) {
            $artifacts->fetchAcuse($object, $cfdi, true);
        }
        setEventMessages('Archivos descargados de Facty.', null, 'mesgs');
    } catch (FactyApiException $e) {
        setEventMessages($e->userMessage(), null, 'errors');
    } catch (Exception $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
    $cfdi = FactyCfdi::fetchByFacture($db, (int) $object->id);
}

if ($action === 'satstatus' && $cfdi !== null && $user->hasRight('factymx', 'cfdi', 'read')) {
    // `force` sólo cuando el usuario lo pide expresamente: cada consulta
    // reenviada al PAC consume un folio de la cuenta.
    $cancelHelper = new FactyCancel($db);
    $satStatus    = $cancelHelper->satStatus($cfdi, true);
    if ($satStatus === null && $cancelHelper->error !== '') {
        setEventMessages($cancelHelper->error, null, 'errors');
    }
}

if ($action === 'cancel' && $cfdi !== null && $user->hasRight('factymx', 'cfdi', 'cancel')) {
    $cancelHelper = new FactyCancel($db);
    $ok = $cancelHelper->cancel(
        $object,
        $cfdi,
        (string) GETPOST('motivo', 'alpha'),
        (string) GETPOST('folio_sustitucion', 'alpha')
    );

    if ($ok) {
        setEventMessages('CFDI cancelado ante el SAT.', null, 'mesgs');
    } else {
        setEventMessages($cancelHelper->error, null, 'errors');
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
if ($cfdi !== null && in_array($cfdi->status, array(FactyCfdi::STATUS_STAMPED, FactyCfdi::STATUS_CANCELLED), true)) {
    $cancelado = ($cfdi->status === FactyCfdi::STATUS_CANCELLED);

    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">Estado</td><td>';
    print $cancelado
        ? '<span class="factymx-status-cancelled">Cancelado</span>'
        : '<span class="factymx-status-stamped">Timbrado</span>';
    print ' ' . factymxEnvBadge($cfdi->env) . '</td></tr>';

    print '<tr><td>Folio fiscal (UUID)</td><td><span class="factymx-request-id">'
        . dol_escape_htmltag((string) $cfdi->uuid) . '</span></td></tr>';
    print '<tr><td>Serie y folio</td><td>' . dol_escape_htmltag(trim($cfdi->serie . ' ' . $cfdi->folio)) . '</td></tr>';
    print '<tr><td>Fecha de timbrado</td><td>' . dol_escape_htmltag((string) $cfdi->stamped_at) . '</td></tr>';
    print '<tr><td>Total</td><td>' . price((float) $cfdi->total) . ' ' . dol_escape_htmltag((string) $cfdi->moneda) . '</td></tr>';

    if ($cancelado) {
        print '<tr><td>Cancelado el</td><td>' . dol_escape_htmltag((string) $cfdi->cancelled_at)
            . ' <span class="opacitymedium">(motivo ' . dol_escape_htmltag((string) $cfdi->cancel_motivo) . ' — '
            . dol_escape_htmltag(FactyCancel::MOTIVOS[$cfdi->cancel_motivo] ?? '') . ')</span></td></tr>';
    }

    // Estatus ante el SAT. Se muestra el valor en caché; consultar de verdad
    // cuesta un folio, así que se hace sólo si el usuario lo pide.
    print '<tr><td>Estatus en el SAT</td><td>';
    if ($satStatus !== null) {
        print '<strong>' . dol_escape_htmltag((string) ($satStatus['estado'] ?? '—')) . '</strong>';
        if (!empty($satStatus['esCancelable'])) {
            print ' <span class="opacitymedium">· ' . dol_escape_htmltag((string) $satStatus['esCancelable']) . '</span>';
        }
        if (!empty($satStatus['cached'])) {
            print ' <span class="opacitymedium">(consultado el '
                . dol_escape_htmltag((string) ($satStatus['checkedAt'] ?? '')) . ')</span>';
        }
    } else {
        print '<span class="opacitymedium">Sin consultar.</span>';
    }
    print ' <a class="button smallpaddingimp" href="' . $_SERVER['PHP_SELF'] . '?facid=' . ((int) $object->id)
        . '&action=satstatus&token=' . newToken() . '">Consultar al SAT</a>';
    print '<br><span class="opacitymedium">Cada consulta al SAT consume un folio de tu cuenta, '
        . 'así que no se hace sola al abrir esta pantalla.</span>';
    print '</td></tr>';

    print '<tr><td>Archivos</td><td>';
    if ($cfdi->xml_path || $cfdi->pdf_path) {
        if ($cfdi->xml_path) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=facture&file='
                . urlencode((string) $cfdi->xml_path) . '">XML</a> ';
        }
        if ($cfdi->pdf_path) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=facture&file='
                . urlencode((string) $cfdi->pdf_path) . '">PDF</a> ';
        }
        if ($cfdi->acuse_path) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=facture&file='
                . urlencode((string) $cfdi->acuse_path) . '">Acuse de cancelación</a> ';
        }
        print '<span class="opacitymedium">— también aparecen en la pestaña Documentos.</span>';
    } else {
        print '<span class="opacitymedium">Todavía no se han descargado.</span>';
    }
    print ' <a class="button smallpaddingimp" href="' . $_SERVER['PHP_SELF'] . '?facid=' . ((int) $object->id)
        . '&action=fetchfiles&token=' . newToken() . '">Descargar de Facty</a>';
    print '</td></tr>';

    print '</table>';

    // --- Cancelación
    if (!$cancelado && $user->hasRight('factymx', 'cfdi', 'cancel')) {
        print '<br><table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Cancelar el CFDI</td></tr>';
        print '<tr class="oddeven"><td colspan="2">';
        print '<div class="warning">Cancelar consume un timbre y no se puede deshacer. '
            . 'El SAT puede además requerir la aceptación del receptor según el caso.</div>';
        print '</td></tr>';

        print '<tr class="oddeven"><td class="titlefield">Motivo</td><td>';
        print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?facid=' . ((int) $object->id) . '">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="cancel">';
        print '<select name="motivo" class="flat" id="factymx-motivo">';
        foreach (FactyCancel::MOTIVOS as $code => $label) {
            print '<option value="' . $code . '">' . $code . ' — ' . dol_escape_htmltag($label) . '</option>';
        }
        print '</select>';
        print '</td></tr>';

        print '<tr class="oddeven"><td>Folio que lo sustituye</td><td>';
        print '<input type="text" name="folio_sustitucion" size="40" placeholder="sólo para el motivo 01">';
        print '<br><span class="opacitymedium">Obligatorio con el motivo 01, y no válido con los demás.</span>';
        print '</td></tr>';

        print '<tr class="oddeven"><td></td><td>';
        print '<input type="submit" class="button butActionDelete" value="Cancelar el CFDI" '
            . 'onclick="return confirm(\'Esto cancela el comprobante ante el SAT y consume un timbre. ¿Continuar?\');">';
        print '</form>';
        print '</td></tr></table>';
    }
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

    // ------------------------------------------------------ CFDI relacionados
    //
    // Para una nota de crédito la relación es obligatoria; para una factura es
    // opcional pero legítima (sustituciones, anticipos, devoluciones), así que
    // el bloque se ofrece siempre en vez de sólo en el egreso.
    $sugerido = '';
    if ($isEgreso && !empty($object->fk_facture_source)) {
        // Si la factura de origen se timbró con este módulo ya tenemos su folio:
        // se propone en vez de pedirle al usuario que lo busque.
        $origen = FactyCfdi::fetchByFacture($db, (int) $object->fk_facture_source);
        if ($origen !== null && $origen->uuid) {
            $sugerido = (string) $origen->uuid;
        }
    }

    $relacionables = factymxRelatableCfdis($db, (int) $object->socid, (int) $object->id);

    print '<tr><td>' . ($isEgreso ? 'CFDI que corrige' : 'CFDI relacionado') . '</td><td>';

    if ($relacionables) {
        print '<select name="uuid_relacionado_sel" class="flat minwidth300">';
        print '<option value="">— ninguno —</option>';
        foreach ($relacionables as $uuid => $label) {
            print '<option value="' . dol_escape_htmltag($uuid) . '"'
                . ($uuid === $sugerido ? ' selected' : '') . '>'
                . dol_escape_htmltag($label) . '</option>';
        }
        print '</select><br>';
    }

    print '<input type="text" name="uuid_relacionado" size="40" value="'
        . dol_escape_htmltag($relacionables ? '' : $sugerido) . '" placeholder="o pega un folio fiscal">';
    print '<br><span class="opacitymedium">La lista sólo trae los CFDI que este módulo timbró para este cliente '
        . 'en el ambiente actual. Si el comprobante se emitió con otra herramienta, pega su folio a mano: '
        . 'el SAT acepta cualquier folio válido.</span>';

    print '<br><select name="tiporelacion" class="flat">';
    $tiposRel = $catalog->all('TipoRelacion');
    if ($tiposRel) {
        foreach ($tiposRel as $code => $label) {
            $sel = ($isEgreso && $code === '01') ? ' selected' : '';
            print '<option value="' . dol_escape_htmltag((string) $code) . '"' . $sel . '>'
                . dol_escape_htmltag($code . ' — ' . $label) . '</option>';
        }
    } else {
        // Sin catálogo disponible, los tres tipos que cubren casi todos los
        // casos reales, para no dejar la pantalla inutilizable.
        print '<option value="01">01 — Nota de crédito de los documentos relacionados</option>';
        print '<option value="03">03 — Devolución de mercancía</option>';
        print '<option value="04">04 — Sustitución de los CFDI previos</option>';
    }
    print '</select>';
    if ($isEgreso) {
        print '<br><span class="opacitymedium">El SAT exige relacionar la nota de crédito con el comprobante que corrige.</span>';
    }
    print '</td></tr>';

    // ---------------------------------------------------------- factura global
    //
    // Ampara las ventas al público en general de un periodo. El receptor pasa a
    // ser el RFC genérico, así que sólo tiene sentido si la factura ya va a ese
    // cliente: ofrecerla sobre un receptor identificado produciría un
    // comprobante que el SAT rechaza, o peor, uno emitido a nombre equivocado.
    if (!$isEgreso) {
        $rfcCliente  = (string) ($object->thirdparty->idprof1 ?? '');
        $esGenerico  = factymxIsRfcGenerico($rfcCliente);

        print '<tr><td>Factura global</td><td>';

        if (!$esGenerico) {
            print '<span class="opacitymedium">Disponible sólo cuando el receptor es el público en general '
                . '(RFC XAXX010101000). El cliente de esta factura es '
                . dol_escape_htmltag($rfcCliente !== '' ? $rfcCliente : 'sin RFC') . '.</span>';
        } else {
            print '<label><input type="checkbox" name="es_global" value="1"> '
                . 'Esta factura ampara ventas al público en general</label>';

            print '<div style="margin-top:6px">';
            print 'Periodicidad ';
            print $catalog->selectHtml('Periodicidad', 'periodicidad', '04', false);
            print ' Mes/bimestre ';
            print $catalog->selectHtml('Meses', 'meses', dol_print_date(dol_now(), '%m'), false);
            print ' Año <input type="number" name="anio" size="5" min="2021" max="2099" value="'
                . dol_print_date(dol_now(), '%Y') . '">';
            print '</div>';
            print '<span class="opacitymedium">El periodo debe corresponder a las ventas que ampara este comprobante.</span>';
        }
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
