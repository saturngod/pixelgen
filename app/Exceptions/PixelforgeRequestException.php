<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A Pixelforge generation request failed. The controller maps the error code to a response.
 */
class PixelforgeRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
