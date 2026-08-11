<?php
/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    admin/setup.php
 * \ingroup factymx
 * \brief   Configuración de conexión: el par producción / pruebas.
 *
 * Los dos ambientes se guardan a la vez y se alterna con un radio. Cambiar de
 * ambiente no debe obligar a recapturar credenciales — es el mismo patrón del
 * par de URLs que estos usuarios ya conocen, y evita que alguien "pruebe algo"
 * pisando la configuración de producción.
 */

// Localizar main.inc.php (el módulo puede estar en custom/ o en la raíz).
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
require_once __DIR__.'/../class/FactyClient.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('admin', 'factymx@factymx'));

if (!$user->admin && !$user->hasRight('factymx', 'config', 'write')) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$env    = GETPOST('env', 'aZ09') === FactyConfig::ENV_PROD ? FactyConfig::ENV_PROD : FactyConfig::ENV_TEST;

$messages = array(); // array<array{level:string,text:string}>

/**
 * Guarda la configuración de UN ambiente.
 *
 * La llave sólo se sobrescribe si el administrador escribió una nueva: el campo
 * se muestra vacío (nunca con la llave real), así que un guardado normal no debe
 * borrar lo que ya funcionaba.
 */
function factymxSaveEnv($db, $conf, string $env, array &$messages): void
{
    $suffix = FactyConfig::suffix($env);

    $base = trim(GETPOST('api_base_'.strtolower($env), 'alpha'));
    if ($base !== '') {
        if (!preg_match('#^https://#i', $base)) {
            // No es purismo: la llave viaja en cada petición. Sobre HTTP plano
            // se entrega a cualquiera que esté en la ruta.
            $messages[] = array('level' => 'errors', 'text' => 'La URL de '.FactyConfig::label($env).' debe usar https://.');
        } else {
            dolibarr_set_const($db, 'FACTYMX_API_BASE_'.$suffix, rtrim($base, '/'), 'chaine', 0, '', $conf->entity);
        }
    }

    $key = trim(GETPOST('api_key_'.strtolower($env), 'restricthtml'));
    if ($key !== '') {
        if (strpos($key, 'fk_') !== 0) {
            $messages[] = array('level' => 'errors', 'text' => 'La API key de '.FactyConfig::label($env).' no tiene el formato esperado (debe empezar con fk_).');
        } else {
            dolibarr_set_const($db, 'FACTYMX_API_KEY_'.$suffix, dolEncrypt($key), 'chaine', 0, '', $conf->entity);
            // La llave cambió: el slug descubierto ya no necesariamente
            // corresponde a esa llave. Se limpia para forzar "Probar conexión"
            // y que no quede apuntando a otra organización.
            dolibarr_set_const($db, 'FACTYMX_ORG_SLUG_'.$suffix, '', 'chaine', 0, '', $conf->entity);
        }
    }
}

/**
 * Probar conexión: identidad, capacidades y — lo importante — comprobar que el
 * host responde el modo de timbrado que corresponde al ambiente.
 */
function factymxTestConnection(string $env, array &$messages): void
{
    global $db, $conf;

    try {
        $client = new FactyClient($env, false);
    } catch (FactyConfigException $e) {
        $messages[] = array('level' => 'errors', 'text' => $e->getMessage());

        return;
    }

    try {
        // Arranque: el administrador pega una llave y nada más. Todas las rutas
        // de negocio llevan el slug de la organización en la URL, así que hace
        // falta un punto de entrada SIN slug que lo revele — Facty resuelve la
        // organización desde la propia llave.
        $slug = FactyConfig::orgSlug($env);
        if ($slug === '') {
            $me   = $client->request('GET', '/v1/orgs/current');
            $slug = isset($me['org']['slug']) ? (string) $me['org']['slug'] : '';
        }
        if ($slug === '') {
            $messages[] = array(
                'level' => 'errors',
                'text'  => 'Facty no devolvió la organización de esta llave. Verifica que la llave sea válida y tenga permisos de lectura.',
            );

            return;
        }

        $ctx = $client->context($slug);
    } catch (FactyTransportException $e) {
        $messages[] = array('level' => 'errors', 'text' => $e->getMessage());

        return;
    } catch (FactyApiException $e) {
        $messages[] = array('level' => 'errors', 'text' => $e->userMessage());

        return;
    }

    // --- La comprobación que evita emitir CFDI reales creyendo que se prueba.
    $expected = FactyConfig::expectedStampingMode($env);
    $actual   = isset($ctx['stampingMode']) ? (string) $ctx['stampingMode'] : '';

    if ($actual !== '' && $actual !== $expected) {
        $messages[] = array(
            'level' => 'errors',
            'text'  => 'Esta llave pertenece a un ambiente de '
                .($actual === 'production' ? 'PRODUCCIÓN' : 'PRUEBAS')
                .' (modo de timbrado: '.$actual.'), pero la estás guardando en el campo de '
                .FactyConfig::label($env).'. '
                .($expected === 'sandbox'
                    ? 'Timbrarías CFDI reales creyendo que estás probando.'
                    : 'Tus facturas de producción no tendrían validez fiscal.')
                .' No se guardó la organización: revisa la llave o cambia el ambiente.',
        );

        return;
    }
    if ($actual === '') {
        // Facty no reportó el modo. No se bloquea, pero tampoco se afirma que
        // todo está bien: quien opere esto merece saber que la comprobación
        // no pudo hacerse.
        $messages[] = array(
            'level' => 'warnings',
            'text'  => 'Facty no reportó el modo de timbrado, así que no se pudo verificar que la llave corresponda al ambiente '
                .FactyConfig::label($env).'. Verifica manualmente antes de timbrar en producción.',
        );
    }

    dolibarr_set_const($db, 'FACTYMX_ORG_SLUG_'.FactyConfig::suffix($env), $slug, 'chaine', 0, '', $conf->entity);

    $org      = isset($ctx['org']['name']) ? (string) $ctx['org']['name'] : $slug;
    $fiscal   = isset($ctx['fiscal']) && is_array($ctx['fiscal']) ? $ctx['fiscal'] : array();
    $rfc      = isset($fiscal['rfc']) ? (string) $fiscal['rfc'] : '—';
    $regimen  = isset($fiscal['regimenFiscal']) ? (string) $fiscal['regimenFiscal'] : '—';
    $lugar    = isset($fiscal['lugarExpedicion']) ? (string) $fiscal['lugarExpedicion'] : '—';
    $csdState = isset($fiscal['csd']['state']) ? (string) $fiscal['csd']['state'] : 'desconocido';
    $saldo    = array_key_exists('timbreBalance', $ctx) && $ctx['timbreBalance'] !== null
        ? (string) $ctx['timbreBalance']
        : 'sin permiso para consultarlo';

    $text = 'Conectado a '.FactyConfig::label($env).' — '.dol_escape_htmltag($org).' ('.dol_escape_htmltag($rfc).')'
        .'<br>Régimen '.dol_escape_htmltag($regimen).' · Lugar de expedición '.dol_escape_htmltag($lugar)
        .' · CSD: '.dol_escape_htmltag($csdState)
        .'<br>Modo de timbrado: <strong>'.dol_escape_htmltag($actual !== '' ? $actual : 'no reportado').'</strong>'
        .'<br>Timbres disponibles: '.dol_escape_htmltag($saldo);

    if ($csdState !== 'active') {
        $text .= '<br><strong>Ojo:</strong> sin un CSD vigente en Facty no vas a poder timbrar.';
    }

    // Complementos que esta organización NO puede emitir: mejor decirlo aquí
    // que dejar que alguien arme un documento y lo rechace el PAC.
    if (isset($ctx['complements']) && is_array($ctx['complements'])) {
        $off = array();
        foreach ($ctx['complements'] as $name => $enabled) {
            if (!$enabled) {
                $off[] = $name;
            }
        }
        if ($off) {
            $text .= '<br>No disponibles en esta cuenta: '.dol_escape_htmltag(implode(', ', $off));
        }
    }

    $messages[] = array('level' => 'mesgs', 'text' => $text);
}

// ---------------------------------------------------------------- acciones
if ($action === 'save' && $user->hasRight('factymx', 'config', 'write')) {
    factymxSaveEnv($db, $conf, FactyConfig::ENV_PROD, $messages);
    factymxSaveEnv($db, $conf, FactyConfig::ENV_TEST, $messages);

    $newEnv = GETPOST('active_env', 'aZ09') === FactyConfig::ENV_PROD
        ? FactyConfig::ENV_PROD
        : FactyConfig::ENV_TEST;
    dolibarr_set_const($db, 'FACTYMX_ENV', $newEnv, 'chaine', 0, '', $conf->entity);

    $timeout = (int) GETPOST('timeout', 'int');
    if ($timeout > 0) {
        dolibarr_set_const($db, 'FACTYMX_TIMEOUT', (string) $timeout, 'chaine', 0, '', $conf->entity);
    }

    if (!$messages) {
        $messages[] = array('level' => 'mesgs', 'text' => 'Configuración guardada.');
    }
}

if ($action === 'test' && $user->hasRight('factymx', 'config', 'write')) {
    factymxTestConnection($env, $messages);
}

// ------------------------------------------------------------------ vista
llxHeader('', 'Facty — Configuración');

print load_fiche_titre('Facty — Configuración', '', 'factymx@factymx');

$head = factymxAdminPrepareHead();
print dol_get_fiche_head($head, 'setup', 'Facty', -1, 'factymx@factymx');

foreach ($messages as $m) {
    setEventMessages($m['text'], null, $m['level']);
}

print factymxEnvBanner();

print '<span class="opacitymedium">'
    .'Facty guarda el certificado (CSD) y timbra ante el SAT. Este módulo sólo manda los datos '
    .'de la factura: aquí no se almacena ningún certificado ni contraseña del PAC.'
    .'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

// --- Ambiente activo
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Ambiente activo</td></tr>';
print '<tr class="oddeven"><td class="titlefield">¿Contra qué ambiente se timbra?</td><td>';
$current = FactyConfig::env();
print '<label><input type="radio" name="active_env" value="test"'.($current === FactyConfig::ENV_TEST ? ' checked' : '').'> '
    .'<strong>Pruebas</strong> — comprobantes sin validez fiscal</label><br>';
print '<label><input type="radio" name="active_env" value="prod"'.($current === FactyConfig::ENV_PROD ? ' checked' : '').'> '
    .'<strong>Producción</strong> — CFDI reales ante el SAT</label>';
print '<br><span class="opacitymedium">Los dos ambientes se configuran por separado y se conservan. '
    .'Son organizaciones distintas en Facty, así que cada uno necesita su propia llave.</span>';
print '</td></tr></table><br>';

// --- Un bloque por ambiente
foreach (array(FactyConfig::ENV_TEST, FactyConfig::ENV_PROD) as $e) {
    $suffix    = strtolower($e);
    $storedKey = FactyConfig::apiKey($e);
    $slug      = FactyConfig::orgSlug($e);

    print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'
        .FactyConfig::label($e).'</td></tr>';

    print '<tr class="oddeven"><td class="titlefield">URL de Facty</td><td>';
    print '<input type="url" class="minwidth300" name="api_base_'.$suffix.'" value="'
        .dol_escape_htmltag(FactyConfig::baseUrl($e)).'">';
    print '</td></tr>';

    print '<tr class="oddeven"><td>API key</td><td>';
    print '<input type="password" class="minwidth300" name="api_key_'.$suffix.'" value="" autocomplete="new-password" placeholder="fk_…">';
    if ($storedKey !== '') {
        print ' <span class="opacitymedium">Guardada: '.dol_escape_htmltag(FactyConfig::mask($storedKey)).'</span>';
    } else {
        print ' <span class="warning">Sin configurar</span>';
    }
    print '<br><span class="opacitymedium">Se guarda cifrada y no se vuelve a mostrar. '
        .'Déjalo vacío para conservar la actual. Créala en Facty → Configuración → API Keys.</span>';
    print '</td></tr>';

    print '<tr class="oddeven"><td>Organización</td><td>';
    print $slug !== ''
        ? dol_escape_htmltag($slug).' <span class="opacitymedium">(detectada automáticamente)</span>'
        : '<span class="opacitymedium">Se detecta al probar la conexión — no hace falta escribirla.</span>';
    print '</td></tr>';

    print '<tr class="oddeven"><td></td><td>';
    print '<a class="button button-save" href="'.$_SERVER['PHP_SELF'].'?action=test&env='.$e.'&token='.newToken().'">'
        .'Probar conexión ('.FactyConfig::label($e).')</a>';
    print '</td></tr>';

    print '</table><br>';
}

print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">Avanzado</td></tr>';
print '<tr class="oddeven"><td class="titlefield">Timeout (segundos)</td><td>';
print '<input type="number" min="5" max="120" name="timeout" value="'.FactyConfig::timeout().'">';
print '</td></tr></table>';

print '<div class="center"><br><input type="submit" class="button" value="Guardar"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
