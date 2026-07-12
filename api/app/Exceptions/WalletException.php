<?php

namespace App\Exceptions;

use Exception;

/**
 * A wallet rule was violated (balance too low, no bank account, a payout
 * already in flight). Rendered as a 422 ApiResponse in bootstrap/app.php so
 * the services can stay free of HTTP concerns.
 */
class WalletException extends Exception
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
