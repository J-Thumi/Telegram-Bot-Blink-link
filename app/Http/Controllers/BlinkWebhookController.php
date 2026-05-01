<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Purchase;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BlinkWebhookController extends Controller
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Handle incoming webhook from Blink when invoice is paid
     * Callback URL: https://your-domain.com/api/blink/webhook
     */
    public function handle(Request $request)
    {
        // Log the raw request for debugging
        Log::info('Blink webhook received', $request->all());

        $clientIp = $request->ip();
        Log::info('Client IP address', ['ip' => $clientIp]);

        $payload = $request->all();

        // Check for receive.lightning event (payment received)
        $eventType = $payload['eventType'] ?? null;
        
        if ($eventType !== 'receive.lightning') {
            Log::info('Ignoring non-payment event', ['eventType' => $eventType]);
            return response()->json(['status' => 'ignored', 'eventType' => $eventType]);
        }

        // Extract transaction data
        $transaction = $payload['transaction'] ?? [];
        
        if (empty($transaction)) {
            Log::error('Webhook missing transaction data', ['payload' => $payload]);
            return response()->json(['error' => 'Missing transaction data'], 400);
        }

        // Get payment hash from initiationVia
        $paymentHash = $transaction['initiationVia']['paymentHash'] ?? null;
        
        if (!$paymentHash) {
            Log::error('Webhook missing payment hash', ['transaction' => $transaction]);
            return response()->json(['error' => 'Missing payment hash'], 400);
        }

        // Get settlement amount in satoshis
        $settlementAmount = $transaction['settlementAmount'] ?? 0;
        
        Log::info('Processing Lightning payment', [
            'payment_hash' => $paymentHash,
            'amount_sats' => $settlementAmount,
            'status' => $transaction['status'],
            'wallet_id' => $payload['walletId']
        ]);

        // Find the invoice by payment_hash
        $invoice = Invoice::where('payment_hash', $paymentHash)->first();

        if (!$invoice) {
            Log::warning('Invoice not found for webhook', [
                'payment_hash' => $paymentHash,
                'searching_in' => 'invoices table'
            ]);
            return response()->json(['status' => 'invoice_not_found', 'payment_hash' => $paymentHash]);
        }

        Log::info('Found invoice', [
            'invoice_id' => $invoice->id,
            'current_status' => $invoice->status,
            'amount_sats' => $invoice->amount_msat / 1000,
            'expected_amount' => $invoice->amount_msat / 1000,
            'received_amount' => $settlementAmount
        ]);

        // Check if already processed
        if ($invoice->isPaid()) {
            Log::info('Invoice already marked as paid', ['invoice_id' => $invoice->id]);
            return response()->json(['status' => 'already_processed', 'invoice_id' => $invoice->id]);
        }

        // Verify amount matches (optional, with tolerance)
        $expectedSats = $invoice->amount_msat / 1000;
        if (abs($expectedSats - $settlementAmount) > 1) { // 1 sat tolerance
            Log::warning('Payment amount mismatch', [
                'expected' => $expectedSats,
                'received' => $settlementAmount,
                'invoice_id' => $invoice->id
            ]);
            // Still process but log warning
        }

        // Mark invoice as paid
        $invoice->markAsPaid();
        $invoice->update([
            'paid_at' => now(),
            'satoshis_paid' => $settlementAmount * 1000, // Update with actual received amount
            'blink_client_ip' => $clientIp,
        ]);
        Log::info('Invoice marked as paid', ['invoice_id' => $invoice->id]);

        // Find the associated purchase
        $purchase = Purchase::where('invoice_id', $invoice->id)->first();
        // Generate single-use Telegram invite link
        $telegramUserId = $purchase->telegram_id;
        if (!$purchase) {
            Log::error('No purchase record found for paid invoice', [
                'invoice_id' => $invoice->id,
                'invoice' => $invoice->toArray()
            ]);
            return response()->json(['error' => 'Purchase not found'], 500);
        }

        if ($invoice->is_instant_buy) {
            // INSTANT: Send images directly to the user
            $this->telegram->sendMessage($telegramUserId, "✅ *Payment Confirmed!* Your papers are being sent below:");
            // Dynamically find the subject due in the next 24h
            $subject = \App\Models\Subject::getNextDue();

            if (!$subject) {
                Log::critical("Payment received for Instant Buy, but NO subject is due in 24h!", [
                    'user_id' => $telegramUserId,
                    'invoice_id' => $invoice->id
                ]);
                
                $this->telegram->sendMessage($telegramUserId, "✅ *Payment Received!* \n\n⚠️ No papers are scheduled for the next 24 hours. Please contact support or wait for the next update.");
                return response()->json(['status' => 'no_subject_due']);
            }

            $this->telegram->fulfillSubjectImages($telegramUserId, $purchase->subject);
        } else {
            // GOAL-BASED: Send the one-time invite link
            Log::info('Generating invite link for user', ['telegram_user_id' => $telegramUserId]);
            $invite = $this->telegram->createSingleUseInviteLink($telegramUserId);
            $purchase->update(['telegram_invite_link' => $invite['invite_link'], 'invite_sent_at' => now()]);
            Log::info('Invite link sent to user', [
            'user_id' => $telegramUserId,
            'invite_link' => $invite['invite_link']
        ]);

            $this->telegram->sendMessage($telegramUserId, "✅ *Payment Confirmed!*\n\nJoin the group: " . $invite['invite_link']);
        }

        return response()->json([
            'status' => 'success',
            'invoice_id' => $invoice->id,
            'user_notified' => true
        ]);
    }
}