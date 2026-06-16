<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Thin Midtrans Snap wrapper. When no server key is configured it returns a
 * mock transaction so local/dev checkout flows still work end-to-end.
 */
class MidtransService
{
    protected ?string $serverKey;

    protected bool $isProduction;

    public function __construct()
    {
        $this->serverKey = config('services.midtrans.server_key');
        $this->isProduction = (bool) config('services.midtrans.is_production');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->serverKey);
    }

    /**
     * Create a Snap transaction and return its token + redirect URL.
     *
     * @param  array{order_id: string, gross_amount: int}  $transaction
     * @param  array{first_name?: string, email?: string}  $customer
     * @param  string|null  $finishUrl  Where Snap redirects the buyer after payment.
     * @return array{token: string|null, redirect_url: string|null, mock: bool}
     */
    public function createSnapTransaction(array $transaction, array $customer = [], ?string $finishUrl = null): array
    {
        if (! $this->isConfigured()) {
            // Mock token lets the frontend flow continue without credentials.
            return [
                'token' => 'mock-'.$transaction['order_id'],
                'redirect_url' => null,
                'mock' => true,
            ];
        }

        $baseUrl = $this->isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $response = (new Client)->post($baseUrl, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($this->serverKey.':'),
            ],
            'json' => array_filter([
                'transaction_details' => $transaction,
                'customer_details' => $customer,
                'callbacks' => $finishUrl ? ['finish' => $finishUrl] : null,
            ]),
            'http_errors' => false,
        ]);

        $data = json_decode((string) $response->getBody(), true) ?: [];

        if ($response->getStatusCode() >= 400) {
            Log::error('Midtrans Snap error', ['status' => $response->getStatusCode(), 'body' => $data]);
        }

        return [
            'token' => $data['token'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'mock' => false,
        ];
    }

    /**
     * Validate a webhook notification signature (sha512).
     */
    public function isValidSignature(string $orderId, string $statusCode, string $grossAmount, string $signature): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        return hash_equals($expected, $signature);
    }
}
