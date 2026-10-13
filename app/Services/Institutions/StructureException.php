<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use InvalidArgumentException;

/**
 * A refusal in the academic structure: a message for the person, and, when it
 * is about a particular field, the field's name so the form can show it
 * beside it.
 */
class StructureException extends InvalidArgumentException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function field(string $field, string $message): self
    {
        return new self($message, [$field => $message]);
    }
}
