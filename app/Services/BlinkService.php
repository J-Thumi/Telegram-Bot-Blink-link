<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlinkService
{
    protected string $apiKey;
    protected string $graphqlUrl;
    protected ?string $btcWalletId = null;
    protected bool $isMockMode;

    public function __construct()
    {
        $this->apiKey = config('services.blink.api_key');
        $this->graphqlUrl = config('services.blink.graphql_url', 'https://api.blink.sv/graphql');
        
        // Enable mock mode for local testing if no API key
        $this->isMockMode = (app()->environment('local') && empty($this->apiKey)) || 
                            (config('app.env') === 'local' && env('BLINK_MOCK_MODE', false));
        
        Log::info('BlinkService initialized', [
            'mode' => $this->isMockMode ? 'MOCK' : 'LIVE',
            'graphql_url' => $this->graphqlUrl,
            'has_api_key' => !empty($this->apiKey)
        ]);
    }

    /**
     * Get BTC wallet ID from Blink
     */
    public function getBtcWalletId(): ?string
    {
        if ($this->isMockMode) {
            return 'mock_btc_wallet_id';
        }

        if ($this->btcWalletId) {
            return $this->btcWalletId;
        }

        $query = <<<GRAPHQL
        query Me {
            me {
                defaultAccount {
                    wallets {
                        id
                        walletCurrency
                    }
                }
            }
        }
        GRAPHQL;

        $response = Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->graphqlUrl, [
            'query' => $query,
            'variables' => (object) []
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $wallets = $data['data']['me']['defaultAccount']['wallets'] ?? [];
            
            foreach ($wallets as $wallet) {
                if ($wallet['walletCurrency'] === 'BTC') {
                    $this->btcWalletId = $wallet['id'];
                    Log::info('Found BTC wallet', ['wallet_id' => $this->btcWalletId]);
                    return $this->btcWalletId;
                }
            }
            
            Log::error('BTC wallet not found', ['wallets' => $wallets]);
        } else {
            Log::error('Failed to get wallet ID', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        }

        return null;
    }

    /**
     * Create a Lightning invoice using GraphQL mutation
     */
    public function createInvoice(int $amountSatoshis, int $expirySeconds = 600): ?array
    {
        if ($this->isMockMode) {
            return $this->createMockInvoice($amountSatoshis, $expirySeconds);
        }

        // Get the BTC wallet ID first
        $walletId = $this->getBtcWalletId();
        if (!$walletId) {
            Log::error('Cannot create invoice: No BTC wallet ID found');
            return null;
        }

        $mutation = <<<GRAPHQL
        mutation LnInvoiceCreate(\$input: LnInvoiceCreateInput!) {
            lnInvoiceCreate(input: \$input) {
                invoice {
                    paymentRequest
                    paymentHash
                    paymentSecret
                    satoshis
                }
                errors {
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'amount' => (string) $amountSatoshis, // Amount must be string
                'walletId' => $walletId,
                'memo' => 'Access to premium Telegram channel' // Optional memo
            ]
        ];

        Log::info('Creating Blink invoice', [
            'wallet_id' => $walletId,
            'amount_sats' => $amountSatoshis
        ]);

        $response = Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->graphqlUrl, [
            'query' => $mutation,
            'variables' => $variables
        ]);

        if (!$response->successful()) {
            Log::error('Blink GraphQL request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
        }

        $result = $response->json();
        
        // Check for GraphQL errors
        if (isset($result['errors'])) {
            Log::error('Blink GraphQL errors', ['errors' => $result['errors']]);
            return null;
        }

        $invoiceData = $result['data']['lnInvoiceCreate']['invoice'] ?? null;
        $errors = $result['data']['lnInvoiceCreate']['errors'] ?? [];

        if (!empty($errors)) {
            Log::error('Blink invoice creation errors', ['errors' => $errors]);
            return null;
        }

        if ($invoiceData) {
            Log::info('Invoice created successfully', [
                'payment_hash' => $invoiceData['paymentHash'],
                'satoshis' => $invoiceData['satoshis']
            ]);
            
            return [
                'id' => $invoiceData['paymentHash'], // Use paymentHash as ID
                'payment_hash' => $invoiceData['paymentHash'],
                'payment_request' => $invoiceData['paymentRequest'],
                'payment_secret' => $invoiceData['paymentSecret'],
                'amount_msat' => $invoiceData['satoshis'] * 1000,
                'satoshis' => $invoiceData['satoshis'],
                'status' => 'pending'
            ];
        }

        Log::error('Unexpected response structure', ['response' => $result]);
        return null;
    }

    /**
     * Check invoice status (optional - for polling)
     * Note: Better to use webhooks for real-time updates
     */
    public function getInvoice(string $paymentHash): ?array
    {
        if ($this->isMockMode) {
            return [
                'id' => $paymentHash,
                'status' => 'pending',
                'paid' => false
            ];
        }

        // Query to check if invoice is paid
        // You might need to implement this based on Blink's GraphQL schema
        // For now, we'll rely on webhooks
        
        Log::warning('getInvoice not fully implemented - use webhooks instead');
        return null;
    }

    /**
     * Check wallet balances (useful for monitoring)
     */
    public function getBalances(): ?array
    {
        if ($this->isMockMode) {
            return [
                'BTC' => ['balance' => 100000, 'currency' => 'BTC'],
                'USD' => ['balance' => 5000, 'currency' => 'USD']
            ];
        }

        $query = <<<GRAPHQL
        query Me {
            me {
                defaultAccount {
                    wallets {
                        walletCurrency
                        balance
                    }
                }
            }
        }
        GRAPHQL;

        $response = Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->graphqlUrl, [
            'query' => $query,
            'variables' => (object) []
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $balances = [];
            $wallets = $data['data']['me']['defaultAccount']['wallets'] ?? [];
            
            foreach ($wallets as $wallet) {
                $balances[$wallet['walletCurrency']] = [
                    'balance' => $wallet['balance'],
                    'currency' => $wallet['walletCurrency']
                ];
            }
            
            return $balances;
        }

        return null;
    }

    /**
     * Mock invoice for local testing
     */
    protected function createMockInvoice(int $amountSatoshis, int $expirySeconds): array
    {
        $paymentHash = 'mock_' . Str::random(32);
        $paymentRequest = 'lnbc' . $amountSatoshis . 'n1' . Str::random(50);
        
        Log::info('Creating MOCK invoice', [
            'amount_satoshis' => $amountSatoshis,
            'payment_hash' => $paymentHash
        ]);
        
        return [
            'id' => $paymentHash,
            'payment_hash' => $paymentHash,
            'payment_request' => $paymentRequest,
            'payment_secret' => Str::random(32),
            'amount_msat' => $amountSatoshis * 1000,
            'satoshis' => $amountSatoshis,
            'status' => 'pending',
            'expires_at' => now()->addSeconds($expirySeconds)->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ];
    }
}