<?php

namespace App\Exceptions;

use Exception;

/**
 * A scoreline cannot be written as posted — a squad tie typed by hand, a
 * finished match with no score, a level result that owes a winner.
 *
 * Rendered as an ApiResponse in bootstrap/app.php so MatchResultPayload can stay
 * free of HTTP concerns — same pattern as WalletException and PaymentException.
 * That is what lets both doors that finish a match (the organizer's editor and
 * the match staff's) raise the *same* refusal with the same message instead of
 * each keeping its own copy of the wording.
 */
class MatchResultException extends Exception
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
