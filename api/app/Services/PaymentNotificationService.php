<?php

namespace App\Services;

use App\Models\PaymentNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only writer of `payment_notifications`.
 *
 * A service rather than a `PaymentNotification::create()` in the controller for
 * two reasons: the payload-to-column mapping is typed once, and so is the
 * normalization of `payment_type` — the one field here that also lands on the
 * order tables, where a stray "QRIS " would split a dashboard group in two.
 *
 * Same shape as the "only thing allowed to move X" services already in here
 * (WalletService, EventPlanOrderService).
 */
class PaymentNotificationService
{
    /**
     * Log one notification, whatever its signature said.
     *
     * Called **before** the signature gate, so this must never be able to break
     * the webhook: a logging failure would turn into a 500, which Midtrans
     * retries and which leaves the order unsettled — exactly the outcome the
     * table exists to explain. Hence the try/catch, the same reasoning as
     * TicketService::sendPurchaseConfirmation().
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(array $payload, bool $signatureValid): ?PaymentNotification
    {
        try {
            return PaymentNotification::create([
                'midtrans_order_id' => $this->string($payload['order_id'] ?? null, 64),
                'transaction_id' => $this->string($payload['transaction_id'] ?? null, 64),
                'transaction_status' => $this->string($payload['transaction_status'] ?? null, 32),
                'payment_type' => self::paymentType($payload['payment_type'] ?? null),
                'fraud_status' => $this->string($payload['fraud_status'] ?? null, 32),
                'status_code' => $this->string($payload['status_code'] ?? null, 8),
                'gross_amount' => is_numeric($payload['gross_amount'] ?? null)
                    ? (float) $payload['gross_amount']
                    : null,
                'transaction_time' => $this->time($payload['transaction_time'] ?? null),
                'signature_valid' => $signatureValid,
                'handled' => false,
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal mencatat notifikasi Midtrans', [
                'order_id' => $payload['order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Stamp the row with the order the webhook turned out to be about.
     *
     * `$row` is nullable because record() above swallows its own failures — the
     * webhook must keep working without a log row, so every caller would
     * otherwise need the same null check.
     */
    public function markHandled(?PaymentNotification $row, string $sourceType, string $sourceId): void
    {
        if (! $row) {
            return;
        }

        try {
            $row->update([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'handled' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal menandai notifikasi Midtrans', [
                'notification_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize the instrument name before it is stored or grouped on.
     *
     * Spelled once, here, because it is written to four tables: this log plus
     * the three order tables. Midtrans sends lower case today, and a dashboard
     * that groups on the raw value would silently show "qris" and "QRIS" as two
     * instruments if that ever changed.
     */
    public static function paymentType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = strtolower(trim($value));

        return $clean === '' ? null : substr($clean, 0, 32);
    }

    private function string(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean === '' ? null : substr($clean, 0, $max);
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
