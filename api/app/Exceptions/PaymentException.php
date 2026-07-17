<?php

namespace App\Exceptions;

use Exception;

/**
 * A payment cannot be started (the organizer's plan has no gateway, or the
 * gateway is off and they never set up a bank account to receive transfers).
 * Rendered as an ApiResponse in bootstrap/app.php so PaymentRails and the
 * payment services can stay free of HTTP concerns — same pattern as
 * WalletException.
 */
class PaymentException extends Exception
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
