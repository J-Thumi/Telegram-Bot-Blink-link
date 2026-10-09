<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BitikaPaymentService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $fixedAmount;
    protected string $lightningAddress;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.bitika.base_url', 'https://bitikaserver.up.railway.app'),
            '/'
        );

        $this->apiKey = config('services.bitika.api_key', '');

        $this->fixedAmount = (int) config(
            'services.bitika.fixed_amount',
            3030
        );

        $this->lightningAddress = config(
            'services.bitika.lightning_address',
            ''
        );
    }

    /**
     * Format Kenyan phone numbers to 254XXXXXXXXX.
     */
    private function formatPhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/\D/', '', $phone);

        if (str_starts_with($cleaned, '0')) {
            return '254' . substr($cleaned, 1);
        }

        if (str_starts_with($cleaned, '254')) {
            return $cleaned;
        }

        if (
            str_starts_with($cleaned, '7') ||
            str_starts_with($cleaned, '1')
        ) {
            return '254' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Initiate an M-Pesa collection and Lightning settlement.
     *
     * @param string $phoneNumber
     * @param int|null $customAmount
     * @param string|null $idempotencyKey
     * @return array
     *
     * @throws Exception
     */
    public function collect(
        string $phoneNumber,
        ?int $customAmount = null,
        ?string $idempotencyKey = null
    ): array {
        Log::info('Initiating Bitika collection', [
            'phone_number' => $phoneNumber,
            'custom_amount' => $customAmount,
            'idempotency_key' => $idempotencyKey,
        ]);
        if (empty($this->apiKey)) {
            throw new Exception('Bitika API key is not configured.');
        }

        if (empty($this->lightningAddress)) {
            throw new Exception('Bitika Lightning address is not configured.');
        }

        $formattedPhone = $this->formatPhoneNumber($phoneNumber);
        $amount = $customAmount ?? $this->fixedAmount;

        $idempotencyKey ??= (string) Str::uuid();

        $payload = [
            'amount' => (string) $amount,
            'phone' => $formattedPhone,
            'lightningAddress' => $this->lightningAddress,
        ];

        $response = Http::timeout(15)
            ->acceptJson()
            ->withToken($this->apiKey)
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post(
                "{$this->baseUrl}/api/v1/xwift/collect",
                $payload
            );

        if ($response->failed()) {
            $this->handleError($response, $payload);

            throw new Exception(
                $response->json('message')
                    ?? $response->json('error')
                    ?? 'Failed to initiate payment via Bitika.'
            );
        }

        return $response->json();
    }

    /**
     * Look up a transaction by transaction code.
     */
    public function getTransaction(string $transactionCode): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->withToken($this->apiKey)
            ->get(
                "{$this->baseUrl}/api/v1/transactions/code/"
                . urlencode($transactionCode)
            );

        if ($response->failed()) {
            $this->handleError($response);

            throw new Exception(
                $response->json('message')
                    ?? $response->json('error')
                    ?? 'Failed to retrieve Bitika transaction.'
            );
        }

        return $response->json();
    }

    /**
     * Get transactions for a phone number.
     */
    public function getTransactionsByPhone(string $phoneNumber): array
    {
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        $response = Http::timeout(15)
            ->acceptJson()
            ->withToken($this->apiKey)
            ->get(
                "{$this->baseUrl}/api/v1/transactions/phone/"
                . urlencode($formattedPhone)
            );

        if ($response->failed()) {
            $this->handleError($response);

            throw new Exception(
                $response->json('message')
                    ?? $response->json('error')
                    ?? 'Failed to retrieve Bitika transactions.'
            );
        }

        return $response->json();
    }

    /**
     * Get the current KES -> sats exchange rate.
     */
    public function getExchangeRate(): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->withToken($this->apiKey)
            ->get(
                "{$this->baseUrl}/api/v1/exchange/rate"
            );

        if ($response->failed()) {
            $this->handleError($response);

            throw new Exception(
                $response->json('message')
                    ?? $response->json('error')
                    ?? 'Failed to retrieve Bitika exchange rate.'
            );
        }

        return $response->json();
    }

    /**
     * Handle and log Bitika API errors.
     */
    private function handleError(
        Response $response,
        array $payload = []
    ): void {
        Log::error('Bitika API request failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'response' => $response->json(),
            'payload' => $payload,
        ]);
    }
}