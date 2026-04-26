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

    public function handle(Request $request)
    {
        // Log the raw webhook for debugging
        Log::info('Blink webhook received', $request->all());

        $payload = $request->all();
        
        // Check for different webhook formats
        $event = $payload['event'] ?? $payload['type'] ?? null;
        
        // Handle only invoice.paid events
        if ($event !== 'invoice.paid') {
            Log::info('Ignoring non-payment event', ['event' => $event]);
            return response()->json(['status' => 'ignored']);
        }

        // Extract data from the webhook
        $data = $payload['data'] ?? [];
        
        // Try different possible field names for payment hash
        $paymentHash = $data['paymentHash'] ?? 
                       $data['payment_hash'] ?? 
                       $payload['paymentHash'] ?? 
                       $payload['payment_hash'] ?? 
                       null;

        if (!$paymentHash) {
            Log::error('Webhook missing payment hash', ['payload' => $payload]);
            return response()->json(['error' => 'Missing payment hash'], 400);
        }

        Log::info('Processing payment for invoice', ['payment_hash' => $paymentHash]);

        // Find the invoice by payment_hash (not by blink_id)
        $invoice = Invoice::where('payment_hash', $paymentHash)->first();

        if (!$invoice) {
            Log::warning('Invoice not found for webhook', ['payment_hash' => $paymentHash]);
            return response()->json(['status' => 'invoice_not_found']);
        }

        Log::info('Found invoice', [
            'invoice_id' => $invoice->id,
            'current_status' => $invoice->status,
            'amount' => $invoice->amount_msat / 1000
        ]);

        // Check if already processed
        if ($invoice->isPaid()) {
            Log::info('Invoice already marked as paid', ['invoice_id' => $invoice->id]);
            return response()->json(['status' => 'already_processed']);
        }

        // Mark invoice as paid
        $invoice->markAsPaid();
        Log::info('Invoice marked as paid', ['invoice_id' => $invoice->id]);

        // Find the associated purchase
        $purchase = Purchase::where('invoice_id', $invoice->id)->first();

        if (!$purchase) {
            Log::error('No purchase record found for paid invoice', ['invoice_id' => $invoice->id]);
            return response()->json(['error' => 'Purchase not found'], 500);
        }

        // Generate single-use Telegram invite link
        $telegramUserId = $purchase->telegram_id;
        
        Log::info('Generating invite link for user', ['telegram_user_id' => $telegramUserId]);

        $inviteLinkData = $this->telegram->createSingleUseInviteLink(
            userId: $telegramUserId,
            expireInSeconds: 604800 // 7 days
        );

        Log::info('Invite link generation result', [
            'invite_link_data' => $inviteLinkData
        ]);

        if (!$inviteLinkData) {
            if(config('app.env') === "local"){
                Log::warning('Failed to create invite link, using placeholder. In local', ['user_id' => $telegramUserId]);
                $inviteLinkData = [
                    'invite_link' => 'https://t.me/joinchat/EXAMPLE', // Placeholder link
                    'id' => 'placeholder_invite_id'
                ];
            } else {
                Log::critical('Failed to create invite link for user', ['user_id' => $telegramUserId]);
                $this->telegram->sendMessage(
                    $telegramUserId, 
                    "❌ Payment confirmed but failed to generate invite link.\n\n"
                    . "Please contact support with your Payment Hash:\n"
                    . "`{$invoice->payment_hash}`"
                );
                return response()->json(['error' => 'Failed to generate invite link'], 500);
            }

        }
        // Extract the invite link ID (Telegram API v7.0+ uses different field names)
        $inviteLinkId = $inviteLinkData['id'] ?? 
                        $inviteLinkData['link_id'] ?? 
                        $inviteLinkData['invite_link_id'] ?? 
                        null;

        // If no ID found, generate one from the link (for fallback)
        if (!$inviteLinkId && isset($inviteLinkData['invite_link'])) {
            $inviteLinkId = 'link_' . md5($inviteLinkData['invite_link']);
            Log::info('Generated fallback invite link ID', ['generated_id' => $inviteLinkId]);
        }

        // Save invite link info to purchase record
        $purchase->update([
            'telegram_invite_link' => $inviteLinkData['invite_link'],
            'telegram_invite_link_id' => $inviteLinkId,
            'invite_sent_at' => now(),
        ]);

        // Send invite link to user
        $amountSats = $invoice->amount_msat / 1000;
        $message = "✅ *Payment Received!*\n\n"
                 . "🎉 Thank you for your payment of {$amountSats} sats!\n\n"
                 . "🔗 Here is your single-use invite link to our private channel:\n"
                 . "{$inviteLinkData['invite_link']}\n\n"
                 . "⚠️ *Important:* This link works only once and will expire in 7 days or after first use.\n\n"
                 . "Click the link above to join and get access!";

        $this->telegram->sendMessage($telegramUserId, $message);
        
        Log::info('Invite link sent to user', [
            'user_id' => $telegramUserId,
            'invite_link_id' => $inviteLinkId
        ]);

        return response()->json(['status' => 'success', 'invoice_id' => $invoice->id]);
    }
}