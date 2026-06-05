<?php

declare(strict_types=1);

namespace Flixly;

/**
 * Thrown by the Flixly client for any non-2xx response or transport
 * failure.
 *
 *   - `$err->getHttpStatus()`   — HTTP status code (0 on transport failure)
 *   - `$err->getErrorCode()`    — Flixly error code string ("invalid_request", "insufficient_credits", ...)
 *   - `$err->getMessage()`      — human-readable message
 *   - `$err->getDetails()`      — optional details array from the error response
 *   - `$err->getRateLimit()`    — parsed X-RateLimit-* headers, if present
 *
 * Note we override the meaning of getCode() — the parent Exception's
 * code is "int" but the Flixly error code is a string, so we expose
 * it via getErrorCode() and leave the int code as the HTTP status.
 */
final class FlixlyError extends \RuntimeException
{
    /** @var string */
    private $errorCode;
    /** @var ?array<string,mixed> */
    private $details;
    /** @var ?array{limit:int, remaining:int, resetAtSec:int} */
    private $rateLimit;

    /**
     * @param ?array<string,mixed> $details
     * @param ?array{limit:int, remaining:int, resetAtSec:int} $rateLimit
     */
    public function __construct(
        int $httpStatus,
        string $errorCode,
        string $message,
        ?array $details,
        ?array $rateLimit
    ) {
        parent::__construct($message, $httpStatus);
        $this->errorCode = $errorCode;
        $this->details   = $details;
        $this->rateLimit = $rateLimit;
    }

    public function getHttpStatus(): int
    {
        return (int) $this->code;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return ?array<string,mixed> */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /** @return ?array{limit:int, remaining:int, resetAtSec:int} */
    public function getRateLimit(): ?array
    {
        return $this->rateLimit;
    }
}
