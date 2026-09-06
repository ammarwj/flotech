<?php

namespace App\Exceptions;

use Exception;

/**
 * A custom domain cannot be assigned or activated (already taken, reserved,
 * event still a draft, DNS not pointing here yet).
 *
 * Rendered as an ApiResponse in bootstrap/app.php so DomainService can stay free
 * of HTTP concerns — same pattern as WalletException and PaymentException.
 */
class DomainException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $errors
     */
    public function __construct(string $message, protected ?array $errors = null, protected int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }
}
