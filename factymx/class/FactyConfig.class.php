<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    class/FactyConfig.class.php
 * \ingroup factymx
 * \brief   Resolución del ambiente activo y sus credenciales.
 *
 * Un único lugar decide qué host, qué llave y qué organización se usan. El
 * resto del módulo pregunta aquí en vez de leer constantes sueltas, para que
 * "en qué ambiente estoy" no pueda contestarse distinto en dos pantallas.
 */
class FactyConfig
{
    const ENV_PROD = 'prod';
    const ENV_TEST = 'test';

    /** Ambiente activo para esta entity. Cualquier valor no reconocido cae en
     *  pruebas: si la configuración está rota, el error seguro es NO timbrar
     *  contra producción. */
    public static function env(): string
    {
        return getDolGlobalString('FACTYMX_ENV') === self::ENV_PROD
            ? self::ENV_PROD
            : self::ENV_TEST;
    }

    public static function isTest(): bool
    {
        return self::env() === self::ENV_TEST;
    }

    /** Sufijo de constante para un ambiente: PROD | TEST. */
    public static function suffix(?string $env = null): string
    {
        return strtoupper($env ?? self::env());
    }

    public static function label(?string $env = null): string
    {
        return ($env ?? self::env()) === self::ENV_PROD ? 'PRODUCCIÓN' : 'PRUEBAS';
    }

    public static function baseUrl(?string $env = null): string
    {
        return rtrim(getDolGlobalString('FACTYMX_API_BASE_' . self::suffix($env)), '/');
    }

    /** Llave en claro. Se guarda cifrada con dolEncrypt y sólo se descifra al
     *  momento de usarla; nunca se registra en bitácora ni se devuelve a la UI. */
    public static function apiKey(?string $env = null): string
    {
        $stored = getDolGlobalString('FACTYMX_API_KEY_' . self::suffix($env));
        if ($stored === '') {
            return '';
        }

        return (string) dolDecrypt($stored);
    }

    /** Slug de la organización, descubierto en "Probar conexión" — el
     *  administrador nunca lo teclea. */
    public static function orgSlug(?string $env = null): string
    {
        return getDolGlobalString('FACTYMX_ORG_SLUG_' . self::suffix($env));
    }

    public static function isConfigured(?string $env = null): bool
    {
        return self::apiKey($env) !== '' && self::baseUrl($env) !== '' && self::orgSlug($env) !== '';
    }

    public static function timeout(): int
    {
        $t = (int) getDolGlobalInt('FACTYMX_TIMEOUT', 30);

        return $t > 0 ? $t : 30;
    }

    /**
     * Enmascara una llave para mostrarla. Sólo el prefijo — una llave no se
     * puede recuperar de Facty, así que enseñarla completa no ayudaría a nadie
     * y sí la expondría en pantalla, capturas y sesiones compartidas.
     */
    public static function mask(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        return substr($plain, 0, 8) . str_repeat('•', 12);
    }

    /**
     * Modo de timbrado que ESPERAMOS del host de un ambiente.
     *
     * Es la mitad local de la comprobación que evita el peor error posible de
     * esta funcionalidad: pegar una llave de producción en el campo de pruebas
     * y emitir CFDI reales creyendo que se está probando. La otra mitad la da
     * Facty en GET /context (`stampingMode`); FactyClient compara las dos.
     */
    public static function expectedStampingMode(?string $env = null): string
    {
        return ($env ?? self::env()) === self::ENV_PROD ? 'production' : 'sandbox';
    }
}
