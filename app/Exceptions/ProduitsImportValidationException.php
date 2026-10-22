<?php

namespace App\Exceptions;

use RuntimeException;

class ProduitsImportValidationException extends RuntimeException
{
    /**
     * @param  array<int, array{line: int, reason: string, type?: string}>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Le fichier Excel contient des erreurs de validation.');
    }

    /**
     * @return array<int, array{line: int, reason: string, type?: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
