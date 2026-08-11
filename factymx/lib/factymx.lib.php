<?php
/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__.'/../class/FactyConfig.class.php';

if (!defined('FACTYMX_VERSION')) {
    define('FACTYMX_VERSION', '0.1.0');
}

/**
 * Pestañas de la pantalla de configuración.
 *
 * @return array
 */
function factymxAdminPrepareHead()
{
    global $langs, $conf;

    $h    = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/factymx/admin/setup.php', 1);
    $head[$h][1] = 'Conexión';
    $head[$h][2] = 'setup';
    $h++;

    $head[$h][0] = dol_buildpath('/factymx/admin/defaults.php', 1);
    $head[$h][1] = 'Valores por omisión';
    $head[$h][2] = 'defaults';
    $h++;

    $head[$h][0] = dol_buildpath('/factymx/admin/diagnostics.php', 1);
    $head[$h][1] = 'Diagnóstico';
    $head[$h][2] = 'diagnostics';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'factymx@factymx');

    return $head;
}

/**
 * Banda permanente de MODO PRUEBAS.
 *
 * Se dibuja en TODAS las pantallas del módulo mientras el ambiente activo sea
 * pruebas. No es decoración: dos hosts y dos llaves significan que tarde o
 * temprano alguien va a mirar un CFDI de sandbox creyendo que es real, o al
 * revés. El costo de recordarlo en cada pantalla es mucho menor que el de esa
 * confusión.
 *
 * @return string HTML
 */
function factymxEnvBanner()
{
    if (!FactyConfig::isTest()) {
        return '';
    }

    $host = FactyConfig::baseUrl();

    return '<div class="factymx-env-banner" role="status">'
        .'<strong>MODO PRUEBAS</strong> — los comprobantes que emitas aquí '
        .'<strong>no tienen validez fiscal</strong>. '
        .'Conectado a '.dol_escape_htmltag($host !== '' ? $host : 'preview.facty.mx').'. '
        .'<a href="'.dol_buildpath('/factymx/admin/setup.php', 1).'">Cambiar ambiente</a>'
        .'</div>';
}

/**
 * Distintivo de ambiente para las listas, por fila.
 *
 * Los documentos se marcan con el ambiente en el que se timbraron, no con el
 * ambiente activo: un CFDI de pruebas sigue siendo de pruebas aunque después
 * cambies a producción.
 *
 * @param  string $env
 * @return string HTML
 */
function factymxEnvBadge($env)
{
    if ($env === FactyConfig::ENV_PROD) {
        return '';
    }

    return '<span class="badge badge-status1 factymx-badge-test" title="Timbrado en el ambiente de pruebas">PRUEBAS</span>';
}

/**
 * Llave de idempotencia determinista.
 *
 * Un doble clic, un reintento tras timeout y una repetición del cron producen
 * exactamente la misma llave, así que Facty devuelve el MISMO CFDI y cobra UN
 * solo timbre. El ambiente va dentro para que las bitácoras sean inequívocas
 * aunque las dos bases de datos nunca se toquen.
 *
 * @param  string $objectType  facture | paiement
 * @param  int    $objectId
 * @return string
 */
function factymxIdempotencyKey($objectType, $objectId)
{
    global $conf;

    return 'dolibarr:'.FactyConfig::env().':'.((int) $conf->entity).':'.$objectType.':'.((int) $objectId);
}
