<?php

namespace App\Exceptions;

use Exception;

/**
 * An event's plan does not cover what was asked for — either the feature is not
 * included at all, or a numeric cap has been reached.
 *
 * Rendered as an ApiResponse in bootstrap/app.php, same pattern as
 * WalletException and PaymentException. It exists because two gates fire from
 * code that cannot return a JsonResponse: EventController::syncCategories()
 * (void, and already throwing ValidationException) and the credit claim inside
 * a transaction, where returning a response would leave the transaction open.
 *
 * Controllers that already `return ApiResponse::error(...)` keep doing so —
 * the shape on the wire is identical either way.
 *
 * The 403 + `errors.feature` shape is load-bearing: `isPlanLimitError()` in
 * web/lib/api/errors.ts detects exactly that pair, and it is the reactive
 * safety net behind every proactive "you can't do this on your plan" panel.
 */
class PlanFeatureException extends Exception
{
    /**
     * @param  array<string, mixed>  $errors  Must carry `feature` — see above.
     */
    public function __construct(string $message, protected array $errors, protected int $status = 403)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }
}
