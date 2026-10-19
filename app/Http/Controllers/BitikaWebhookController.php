<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\PurchaseFulfillmentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BitikaWebhookController extends Controller
{
    public function __construct(
        protected PurchaseFulfillmentService $fulfillment,
        protected TelegramService $telegram
    ) {}

    public function handle(Request $request)
    {
        Log::info('Bitika webhook received', [
            'keys' => array_keys($request->all()),
        ]);

        $payload = $request->all();

        // Handle nested payload schemas safely
        $data = $payload['response'] ?? $payload['data'] ?? $payload;
        $innerData = $data['data'] ?? $data; // Handles nested event payloads if present

        $transactionCode =
            $innerData['transaction_code']
            ?? $innerData['transactionCode']
            ?? $data['transaction_code']
            ?? $data['transactionCode']
            ?? $data['reference']
            ?? null;

        $status = strtolower((string) (
            $innerData['status']
            ?? $data['status']
            ?? $data['transaction_status']
            ?? ''
        ));

        if (!$transactionCode) {
            Log::warning('Bitika callback has no transaction reference.');

            return response()->json([
                'status' => 'missing_transaction_reference',
            ], 400);
        }

        $invoice = Invoice::where('bitika_transaction_code', $transactionCode)
            ->with('purchase')
            ->first();

        if (!$invoice) {
            Log::warning('Bitika transaction has no matching invoice.', [
                'transaction_code' => $transactionCode,
            ]);

            return response()->json([
                'status' => 'invoice_not_found',
            ], 404);
        }

        // Handle Success State
        if (in_array($status, ['success', 'successful', 'completed', 'fulfilled'], true)) {
            return $this->handleSuccessfulPayment($invoice);
        }

        // Handle Failure / Cancelled State
        if (in_array($status, ['failed', 'cancelled', 'expired', 'declined'], true)) {
            $declineReason = $innerData['decline_reason'] 
                ?? $innerData['message'] 
                ?? $data['decline_reason'] 
                ?? 'Payment failed or was cancelled.';

            return $this->handleFailedPayment($invoice, $declineReason, $innerData);
        }

        Log::info('Bitika callback status unhandled or intermediate.', [
            'transaction_code' => $transactionCode,
            'status' => $status,
        ]);

        return response()->json(['status' => 'ignored']);
    }

    protected function handleSuccessfulPayment(Invoice $invoice)
    {
        $result = DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status === Invoice::STATUS_PAID) {
                return [
                    'invoice' => $lockedInvoice,
                    'already_paid' => true,
                ];
            }

            $lockedInvoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            return [
                'invoice' => $lockedInvoice->fresh(),
                'already_paid' => false,
            ];
        });

        if ($result['already_paid']) {
            Log::info('Bitika invoice already processed.', ['invoice_id' => $invoice->id]);
            return response()->json(['status' => 'already_processed']);
        }

        try {
            $this->fulfillment->fulfill($result['invoice']);
        } catch (Throwable $e) {
            Log::error('Failed executing purchase fulfillment.', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'invoice_id' => $invoice->id,
        ]);
    }

    protected function handleFailedPayment(Invoice $invoice, string $reason, array $metadata)
    {
        $result = DB::transaction(function () use ($invoice, $reason, $metadata) {
            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedInvoice->status, ['failed', 'cancelled', 'paid'], true)) {
                return [
                    'invoice' => $lockedInvoice,
                    'already_handled' => true,
                ];
            }

            $lockedInvoice->update([
                'status' => 'failed',
                'payment_error' => $reason,
                'payment_metadata' => $metadata,
            ]);

            return [
                'invoice' => $lockedInvoice->fresh(),
                'already_handled' => false,
            ];
        });

        if ($result['already_handled']) {
            return response()->json(['status' => 'already_processed']);
        }

        // Notify user on Telegram if purchase and telegram_id exist
        $telegramId = optional($invoice->purchase)->telegram_id;
        if ($telegramId) {
            try {
                $this->telegram->sendMessage(
                    (string) $telegramId,
                    "❌ *Payment Failed*\n\nReason: {$reason}\n\nYou can try again using /start or /buy."
                );
            } catch (Throwable $e) {
                Log::error('Failed sending payment failure notification via Telegram.', [
                    'telegram_id' => $telegramId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'status' => 'recorded_failure',
            'invoice_id' => $invoice->id,
        ]);
    }
}