<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class WooCommerceException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        ?Throwable $previous = null,
        public readonly bool $imageError = false,
    )
    {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
