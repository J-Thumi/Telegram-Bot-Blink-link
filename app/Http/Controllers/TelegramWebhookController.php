<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use App\Services\BlinkService;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    protected TelegramService $telegram;
    protected BlinkService $blink;

    public function __construct(TelegramService $telegram, BlinkService $blink)
    {
        $this->telegram = $telegram;
        $this->blink = $blink;
    }

    /**
     * Handle incoming updates from Telegram.
     * This is the endpoint you set as your bot's webhook.
     */
    public function handle(Request $request)
    {
        $update = $request->all();
        Log::info('Telegram webhook received', $update);

        // Check if it's a message and has text
        if (isset($update['message']['text'])) {
            $chatId = $update['message']['chat']['id'];
            $text = trim($update['message']['text']);
            $telegramUserId = $update['message']['from']['id'];

            Log::info("Received message from user {$telegramUserId}: {$text}");

            // Handle the /start or /buy command
            if ($text === '/start' || $text === '/buy') {
                $this->handlePurchaseCommand($chatId, $telegramUserId);
            } else {
                $this->telegram->sendMessage($chatId, "🤖 Use /buy to get access.");
            }
        }else {
            Log::info('Received non-text message, ignoring.', $update);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Core logic for the purchase flow.
     */
   protected function handlePurchaseCommand(string $chatId, string $telegramUserId)
    {
        // 1. Check if user already has an active, unpaid invoice
        $existingPurchase = Purchase::where('telegram_id', $telegramUserId)
                                    ->whereHas('invoice', function($q) {
                                        $q->where('status', 'pending');
                                    })->first();

        if ($existingPurchase) {
            $this->telegram->sendMessage($chatId, "⏳ You already have a pending payment. Please complete or wait for it to expire.");
            return;
        }

        // 2. Create a new invoice with Blink
        $amountInSatoshis = config('services.blink.invoice_amount'); // Example: 100 sats. Change as needed.
        Log::info("Creating Blink invoice for user {$telegramUserId} with amount {$amountInSatoshis} sats");

        $blinkInvoice = $this->blink->createInvoice($amountInSatoshis);

        if (!$blinkInvoice) {
            $this->telegram->sendMessage($chatId, "❌ Payment system error. Please try again later.");
            Log::error("Failed to create Blink invoice for user {$telegramUserId}");
            return;
        }

        // 3. Store invoice in our database
        $invoice = Invoice::create([
            'blink_id' => $blinkInvoice['id'],
            'payment_hash' => $blinkInvoice['payment_hash'],
            'payment_request' => $blinkInvoice['payment_request'], // bolt11 string
            'amount_msat' => $blinkInvoice['amount_msat'],
            'status' => 'pending',
        ]);

        Log::info("Invoice stored: {$invoice}");
        
        // 4. Associate purchase with this invoice
        Purchase::create([
            'invoice_id' => $invoice->id,
            'telegram_id' => $telegramUserId,
        ]);
        Log::info("Purchase record created for user {$telegramUserId} with invoice ID {$invoice->id}");

        // 5. Send main message with payment button (Bitika link only)
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💸 Pay via M-Pesa (Bitika)', 'url' => 'https://bitika.xyz']
                ]
            ]
        ];

        $mainMessageText = sprintf(
            "⚡️ *Pay %s sats to get your invite link.*\n\n"
            . "💰 Amount: `%s sats`\n\n"
            . "📌 *Instructions:*\n"
            . "1️⃣ Click the button below to open Bitika\n"
            . "2️⃣ Copy the invoice from the next message\n"
            . "3️⃣ Paste it in Bitika and complete payment via M-Pesa\n"
            . "4️⃣ Wait a few seconds for confirmation\n\n"
            . "⏳ Invoice expires in 10 minutes.",
            $amountInSatoshis,
            $amountInSatoshis
        );

        $this->telegram->sendMessage($chatId, $mainMessageText, $keyboard);
        // 6. Send invoice as separate message (copyable)
        $invoiceMessageText = sprintf(
            "`%s`",
            $invoice->payment_request
        );

        $this->telegram->sendMessage($chatId, $invoiceMessageText);
    }

}