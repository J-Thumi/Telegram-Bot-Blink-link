<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\PurchaseFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BitikaWebhookController extends Controller
{
    public function __construct(
        protected PurchaseFulfillmentService $fulfillment
    ) {}

    public function handle(Request $request)
    {
        // Do not log sensitive callback headers or credentials.
        Log::info('Bitika webhook received', [
            'keys' => array_keys($request->all()),
        ]);

        /*
         * Verify the webhook using Bitika's documented signature
         * or authentication mechanism before trusting this payload.
         *
         * Do not assume the request is authentic just because it
         * contains a transaction code.
         */

        $payload = $request->all();

        // Adapt these paths to Bitika's actual callback schema.
        $data = $payload['response'] ?? $payload['data'] ?? $payload;

        $transactionCode =
            $data['transaction_code']
            ?? $data['transactionCode']
            ?? $data['reference']
            ?? null;

        $status = strtolower((string) (
            $data['status'] ?? $data['transaction_status'] ?? ''
        ));

        if (!$transactionCode) {
            Log::warning('Bitika callback has no transaction reference.');

            return response()->json([
                'status' => 'missing_transaction_reference',
            ], 400);
        }

        // Replace these success values with the exact documented
        // successful terminal status from Bitika.
        if (!in_array($status, ['success', 'successful', 'completed', 'fulfilled'], true)) {
            Log::info('Bitika payment is not confirmed as successful.', [
                'transaction_code' => $transactionCode,
                'status' => $status,
            ]);

            // Acknowledge non-success callbacks without fulfilling.
            return response()->json([
                'status' => 'not_paid',
            ]);
        }

        $invoice = Invoice::where(
            'bitika_transaction_code',
            $transactionCode
        )->first();

        if (!$invoice) {
            Log::warning('Bitika transaction has no matching invoice.', [
                'transaction_code' => $transactionCode,
            ]);

            return response()->json([
                'status' => 'invoice_not_found',
            ], 404);
        }

        // Lock and update the invoice atomically.
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

            // Only mark as paid for a verified success callback.
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
            Log::info('Bitika invoice already processed.', [
                'invoice_id' => $invoice->id,
            ]);

            return response()->json([
                'status' => 'already_processed',
            ]);
        }

        $this->fulfillment->fulfill($result['invoice']);

        return response()->json([
            'status' => 'success',
            'invoice_id' => $invoice->id,
        ]);
    }
}
