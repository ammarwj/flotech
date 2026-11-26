<?php

namespace App\Exceptions;

use Exception;

/**
 * An event operator invite cannot be sent, resent, revoked or accepted (no
 * category checked, invite already used/expired, wrong account accepting).
 *
 * Rendered as an ApiResponse in bootstrap/app.php so EventOperatorService can
 * stay free of HTTP concerns — same pattern as WalletException, PaymentException
 * and DomainException.
 */
class EventOperatorException extends Exception
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
