<?php

/* Copyright (C) 2026 Facty — GPLv3, see LICENSE. */

/**
 * \file    class/FactyExceptions.class.php
 * \ingroup factymx
 * \brief   Errores del cliente de Facty.
 *
 * Están separados por tipo porque la reacción correcta a cada uno es
 * distinta, y confundirlos cuesta dinero: un fallo de transporte deja el
 * resultado DESCONOCIDO y obliga a reconciliar, mientras que un error de
 * negocio (422, 403) es definitivo y reintentarlo sólo repite el error.
 */

/** La configuración del módulo está incompleta o es inconsistente. */
class FactyConfigException extends Exception
{
}

/**
 * Fallo de red: la petición NO llegó, o no sabemos si llegó.
 *
 * Es una excepción aparte a propósito. Un timeout no es "no se timbró", es "no
 * sé": la petición puede haber llegado y el CFDI puede existir ya. Quien la
 * atrape debe encolar una reconciliación por idempotency_key, nunca reintentar
 * a ciegas — cada reintento a ciegas puede gastar un timbre real.
 */
class FactyTransportException extends Exception
{
}

/**
 * Error de negocio devuelto por Facty, con el sobre estándar
 * { error, code, requestId, fieldErrors? }.
 */
class FactyApiException extends Exception
{
    public int $httpStatus;
    public string $factyCode;
    public ?string $requestId;
    /** @var array<string,string> */
    public array $fieldErrors;

    public function __construct(
        int $httpStatus,
        string $message,
        string $factyCode = '',
        ?string $requestId = null,
        array $fieldErrors = []
    ) {
        parent::__construct($message, $httpStatus);
        $this->httpStatus  = $httpStatus;
        $this->factyCode   = $factyCode;
        $this->requestId   = $requestId;
        $this->fieldErrors = $fieldErrors;
    }

    /**
     * ¿Tiene sentido reintentar esto solo?
     *
     * 401/403/402/422 son definitivos: la llave está mal, falta un permiso, no
     * hay timbres o el documento es inválido. Reintentar sin que cambie nada
     * fuera sólo repite el mismo error y, en el caso de 429, empeora.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 429 || $this->httpStatus >= 500;
    }

    /** Mensaje accionable para el usuario, en vez del texto crudo del API. */
    public function userMessage(): string
    {
        switch ($this->factyCode) {
            case 'UNAUTHORIZED':
            case 'INVALID_API_KEY':
                return 'La API key de Facty es inválida o fue revocada. Revísala en la configuración del módulo.';
            case 'MISSING_SCOPE':
                return 'Tu llave de Facty no tiene el permiso necesario para esta operación: ' . $this->getMessage();
            case 'ORG_MISMATCH':
                return 'La llave configurada pertenece a otra organización de Facty.';
            case 'INSUFFICIENT_TIMBRES':
                return 'No hay timbres disponibles en Facty. Recarga tu saldo para continuar.';
            case 'RATE_LIMITED':
                return 'Facty está limitando las peticiones. El módulo reintentará automáticamente.';
            default:
                return $this->getMessage();
        }
    }
}
