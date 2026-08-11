<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

require_once __DIR__ . '/FactyConfig.class.php';
require_once __DIR__ . '/FactyExceptions.class.php';

/**
 * \file    class/FactyClient.class.php
 * \ingroup factymx
 * \brief   Único punto del módulo que toca la red.
 *
 * Resuelve host, llave y organización del AMBIENTE ACTIVO al construirse, y no
 * los vuelve a leer: una petición no puede empezar en pruebas y terminar en
 * producción porque alguien cambió el switch a la mitad.
 */
class FactyClient
{
    private string $env;
    private string $baseUrl;
    private string $apiKey;
    private string $orgSlug;
    private int $timeout;

    /** @var callable|null Registrador opcional: fn(array $entry): void */
    private $logger;

    /**
     * @param string|null $env    Ambiente explícito; por defecto el activo.
     * @param bool        $requireOrg  false durante "Probar conexión", cuando
     *                                 todavía no conocemos el slug.
     */
    public function __construct(?string $env = null, bool $requireOrg = true)
    {
        $this->env     = $env ?? FactyConfig::env();
        $this->baseUrl = FactyConfig::baseUrl($this->env);
        $this->apiKey  = FactyConfig::apiKey($this->env);
        $this->orgSlug = FactyConfig::orgSlug($this->env);
        $this->timeout = FactyConfig::timeout();

        if ($this->baseUrl === '') {
            throw new FactyConfigException(
                'Falta la URL de Facty para el ambiente ' . FactyConfig::label($this->env) . '.'
            );
        }
        if ($this->apiKey === '') {
            throw new FactyConfigException(
                'Falta la API key de Facty para el ambiente ' . FactyConfig::label($this->env)
                . '. Configúrala en Inicio → Configuración → Módulos → Facty.'
            );
        }
        if ($requireOrg && $this->orgSlug === '') {
            throw new FactyConfigException(
                'Todavía no se ha probado la conexión con Facty en el ambiente '
                . FactyConfig::label($this->env) . '. Usa "Probar conexión" para completarla.'
            );
        }
    }

    public function env(): string
    {
        return $this->env;
    }

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /** Ruta dentro de la organización: /authenticated/{org}/... */
    public function orgPath(string $suffix): string
    {
        return '/authenticated/' . rawurlencode($this->orgSlug) . '/' . ltrim($suffix, '/');
    }

    /**
     * GET /authenticated/{org}/context — verificación de capacidades.
     *
     * Además de identidad y saldo, devuelve `stampingMode`: contra qué PAC
     * timbra ESTE host. Es lo que permite rechazar una llave de producción
     * guardada en el campo de pruebas antes de emitir nada.
     */
    public function context(string $orgSlug = ''): array
    {
        $slug = $orgSlug !== '' ? $orgSlug : $this->orgSlug;
        if ($slug === '') {
            throw new FactyConfigException('Se requiere el slug de la organización para consultar el contexto.');
        }

        return $this->request('GET', '/authenticated/' . rawurlencode($slug) . '/context');
    }

    /**
     * Ejecuta una petición y devuelve el cuerpo decodificado.
     *
     * @throws FactyTransportException  fallo de red — resultado DESCONOCIDO
     * @throws FactyApiException        respuesta de error de Facty
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        $url       = $this->baseUrl . $path;
        $requestId = null;
        $startedAt = microtime(true);

        $headers = array(
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: factymx/' . FACTYMX_VERSION . ' dolibarr/' . DOL_VERSION,
        );

        $ch = curl_init($url);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            // Nunca se desactivan. El módulo manda una credencial en cada
            // petición; sin verificar el certificado, cualquiera en la ruta
            // puede quedarse con ella.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$requestId) {
                if (stripos($header, 'x-request-id:') === 0) {
                    $requestId = trim(substr($header, 13));
                }

                return strlen($header);
            },
        ));

        $this->applyProxy($ch);

        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($raw === false) {
            $this->log($method, $path, 0, null, $requestId, $durationMs, 'transport: ' . $curlErr);

            throw new FactyTransportException(
                'No se pudo contactar a Facty (' . $curlErr . '). El resultado es DESCONOCIDO: '
                . 'la petición pudo haber llegado. No reintentes sin reconciliar.'
            );
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = array();
        }

        $code = isset($body['code']) ? (string) $body['code'] : '';
        $this->log($method, $path, $status, $code, $requestId, $durationMs, null);

        if ($status >= 400) {
            throw new FactyApiException(
                $status,
                isset($body['error']) ? (string) $body['error'] : ('Error HTTP ' . $status),
                $code,
                $requestId ?? (isset($body['requestId']) ? (string) $body['requestId'] : null),
                isset($body['fieldErrors']) && is_array($body['fieldErrors']) ? $body['fieldErrors'] : array()
            );
        }

        return $body;
    }

    /**
     * Muchas instalaciones de Dolibarr son autoalojadas y salen a internet por
     * un proxy, o no salen. Se reutiliza el proxy que Dolibarr ya tiene
     * configurado para no pedir la misma información dos veces.
     */
    private function applyProxy($ch): void
    {
        if (!getDolGlobalString('MAIN_PROXY_USE')) {
            return;
        }
        curl_setopt($ch, CURLOPT_PROXY, getDolGlobalString('MAIN_PROXY_HOST'));
        curl_setopt($ch, CURLOPT_PROXYPORT, getDolGlobalInt('MAIN_PROXY_PORT'));
        $user = getDolGlobalString('MAIN_PROXY_USER');
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $user . ':' . getDolGlobalString('MAIN_PROXY_PASS'));
        }
    }

    /**
     * Bitácora. NUNCA registra la llave ni el cuerpo de la petición: el
     * receptor (RFC, nombre, código postal) es dato personal, y la llave da
     * acceso completo a la organización en Facty.
     */
    private function log(
        string $method,
        string $path,
        int $status,
        ?string $code,
        ?string $requestId,
        int $durationMs,
        ?string $message
    ): void {
        dol_syslog(
            'FactyClient ' . $method . ' ' . $path . ' → ' . $status
            . ' env=' . $this->env . ' req=' . ($requestId ?? '-'),
            $status >= 400 || $status === 0 ? LOG_WARNING : LOG_INFO
        );

        if ($this->logger !== null) {
            call_user_func($this->logger, array(
                'env'              => $this->env,
                'method'           => $method,
                'path'             => $path,
                'http_status'      => $status,
                'facty_code'       => $code,
                'facty_request_id' => $requestId,
                'duration_ms'      => $durationMs,
                'message'          => $message,
            ));
        }
    }
}
