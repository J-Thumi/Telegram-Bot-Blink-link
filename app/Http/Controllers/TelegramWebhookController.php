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

        $clientIp = $request->ip();
        Log::info('Client IP address', ['ip' => $clientIp]);

        // --- 1. HANDLE CALLBACK QUERIES (Button Clicks) ---
        if (isset($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];
            $data = $callbackQuery['data']; 
            $chatId = $callbackQuery['message']['chat']['id'];
            $telegramUserId = $callbackQuery['from']['id'];
            
            // Extract user info for the invoice
            $firstName = $callbackQuery['from']['first_name'] ?? '';
            $lastName = $callbackQuery['from']['last_name'] ?? '';
            $fullName = trim($firstName . ' ' . $lastName) ?: 'No name';
            $username = $callbackQuery['from']['username'] ?? 'No username';

            Log::info("Callback received from user {$username}: {$data}");

            if ($data === 'buy_type_instant') {
                $this->processInvoiceGeneration($chatId, $telegramUserId, true, $fullName, $username, $clientIp);
            } elseif ($data === 'buy_type_goal') {
                $this->processInvoiceGeneration($chatId, $telegramUserId, false, $fullName, $username, $clientIp);
            }

            // Always answer the callback so the user doesn't see a loading spinner
            // $this->telegram->answerCallbackQuery($callbackQuery['id']);
            return response()->json(['status' => 'ok']);
        }

        // --- 2. HANDLE STANDARD MESSAGES (Existing Logic) ---
        if (isset($update['message']['text'])) {
            $chatId = $update['message']['chat']['id'];
            $text = trim($update['message']['text']);
            $telegramUserId = $update['message']['from']['id'];

            $firstName = $update['message']['from']['first_name'] ?? '';
            $lastName = $update['message']['from']['last_name'] ?? '';
            $fullName = trim($firstName . ' ' . $lastName) ?: 'No name';
            $username = $update['message']['from']['username'] ?? 'No username';

            Log::info("Received message from user {$username}: {$text}");

            $isOwner = $this->isBotOwner($telegramUserId);

            if ($text === '/start' || $text === '/buy') {
                // This now triggers the "Choice" message (Instant vs Goal)
                $this->handlePurchaseCommand($chatId, $telegramUserId, $fullName, $username, $clientIp);
            } elseif ($text === '/resetGroup' && $isOwner) {
                Log::info("User {$username} initiated group reset.");
                $this->handleResetGroup($chatId, $telegramUserId);
            } elseif ($text === '/cancelInvoices') {
                Log::info("User {$username} requested to cancel pending invoices.");
                $this->canclelPendingInvoices($chatId, $telegramUserId);
            } else {
                Log::info("Received unknown command from user {$username}: {$text}");
                $this->telegram->sendMessage($chatId, "🤖 Use /buy to get access.");
            }
        } else {
            Log::info('Received non-text message or unsupported update, ignoring.', $update);
        }

        return response()->json(['status' => 'ok']);
    }
    protected function canclelPendingInvoices(string $chatId, string $telegramUserId){
        $pendingPurchases = Purchase::where('telegram_id', $telegramUserId)
                                    ->whereHas('invoice', function($q) {
                                        $q->where('status', 'pending');
                                    })->get();

        if ($pendingPurchases->isEmpty()) {
            $this->telegram->sendMessage($chatId, "✅ You have no pending invoices to cancel.");
            return;
        }

        foreach ($pendingPurchases as $purchase) {
            $invoice = $purchase->invoice;
            if ($invoice && $invoice->status === 'pending') {
                $invoice->status = 'cancelled';
                $invoice->save();
                Log::info("Cancelled invoice {$invoice->id} for user {$telegramUserId}");
            }
        }

        $this->telegram->sendMessage($chatId, "✅ All your pending invoices have been cancelled.");
    }
    protected function isBotOwner(string $telegramUserId): bool
    {
        $ownerId = config('services.telegram.owner_id');
        return $telegramUserId === $ownerId;
    }

    /**
     * Handle the /reset-group command - removes all non-admin members
     */
    protected function handleResetGroup(string $chatId, string $telegramUserId)
    {
        Log::info("Reset group command initiated by {$telegramUserId}");
        
        // Send initial status message
        $this->telegram->sendMessage($chatId, "🔄 Starting group reset... This may take a few moments.");
        
        try {
            // Get all chat members
            $groupChatId = config('services.telegram.channel_id'); // Ensure we are targeting the correct chat
            $members = $this->telegram->getChatMembers($groupChatId);
            
            if (!$members || empty($members)) {
                $this->telegram->sendMessage($chatId, "❌ Unable to fetch group members. Make sure bot is admin. Falling back to database members.");

                Log::error("Failed to fetch members for Group chat ID {$groupChatId}.");
            }

            $totalMembers = count($members);
            $removedCount = 0;
            $adminCount = 0;
            $failedCount = 0;
            
            // Send progress update
            $this->telegram->sendMessage($chatId, "📊 Found {$totalMembers} members. Removing non-admin users...");
            
            foreach ($members as $member) {
                $userId = $member['user']['id'];
                $userName = $member['user']['username'] ?? $member['user']['first_name'] ?? 'Unknown';
                $status = $member['status'] ?? '';
                
                // Skip if user is an administrator or creator
                if ($status === 'administrator' || $status === 'creator') {
                    $adminCount++;
                    Log::info("Skipping admin/creator", [
                        'user_id' => $userId,
                        'name' => $userName,
                        'status' => $status
                    ]);
                    continue;
                }
                
                // Skip the bot itself
                if ($userId === config('services.telegram.bot_user_id')) {
                    continue;
                }
                
                // Remove the user
                $removed = $this->telegram->kickChatMember($groupChatId, $userId);
                
                if ($removed) {
                    $removedCount++;
                    Log::info("Removed user", [
                        'user_id' => $userId,
                        'name' => $userName
                    ]);
                } else {
                    $failedCount++;
                    Log::warning("Failed to remove user", [
                        'user_id' => $userId,
                        'name' => $userName
                    ]);
                }
                
                // Small delay to avoid hitting rate limits
                usleep(50000); // 50ms delay
            }
            
            // Send summary
            $summary = "✅ *Group Reset Complete!*\n\n"
                     . "📊 *Statistics:*\n"
                     . "├ Total members: {$totalMembers}\n"
                     . "├ 👑 Admins kept: {$adminCount}\n"
                     . "├ 🗑️ Removed users: {$removedCount}\n"
                     . "└ ❌ Failed removals: {$failedCount}\n\n"
                     . "🔒 The group has been reset. Only admins remain.";
            
            $this->telegram->sendMessage($chatId, $summary);
            Log::info("Group reset completed", [
                'total' => $totalMembers,
                'removed' => $removedCount,
                'admins_kept' => $adminCount,
                'failed' => $failedCount
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error during group reset", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->telegram->sendMessage($chatId, "❌ Error during group reset: " . $e->getMessage());
        }
    }

    /**
     * Core logic for the purchase flow.
     */
//    protected function handlePurchaseCommand(string $chatId, string $telegramUserId, string $fullName, string $username, string $clientIp)
//     {
//         // 1. Check if user already has an active, unpaid invoice
//         $existingPurchase = Purchase::where('telegram_id', $telegramUserId)
//                                     ->whereHas('invoice', function($q) {
//                                         $q->where('status', 'pending');
//                                     })->first();

//         if ($existingPurchase ) {
//             $expireInSeconds = (int) config('services.blink.invoice_expiry', 600); // Default to 10 minutes
//             $expireDate = $existingPurchase->created_at->addSeconds($expireInSeconds);

//             if(now()->greaterThan($expireDate)){
//                 // Mark invoice as expired
//                 $existingPurchase->invoice->status = 'expired';
//                 $existingPurchase->invoice->save();
//                 Log::info("Expired old invoice for user {$telegramUserId} with invoice ID {$existingPurchase->invoice->id}");
//             }else
            
//             {
//                 $this->telegram->sendMessage($chatId, "⏳ You already have a pending payment. Please complete, wait for it to expire. If you want to cancel pending payments, use /cancelInvoices.");
//             Log::info("User {$telegramUserId} attempted to create a new invoice but has an existing pending invoice ID {$existingPurchase->invoice->id}");
//             return;

//         }
//         }
//         // 2. Create a new invoice with Blink
//         $amountInSatoshis = (int) config('services.blink.invoice_amount'); // Example: 100 sats. Change as needed.
//         $instantBuyAmount = (int) config('services.blink.instant_buy_amount', 100); // Default to 100 sats
//         Log::info("Creating Blink invoice for user {$telegramUserId} with amount {$amountInSatoshis} sats");

//         $invoiceExpiry = config('services.blink.invoice_expiry', 600); // Invoice expiry in seconds (default 10 minutes)
//         $blinkInvoice = $this->blink->createInvoice($amountInSatoshis, $invoiceExpiry);

//         if (!$blinkInvoice) {
//             $this->telegram->sendMessage($chatId, "❌ Payment system error. Please try again later.");
//             Log::error("Failed to create Blink invoice for user {$telegramUserId}");
//             return;
//         }

//         // 3. Store invoice in our database
//         $invoice = Invoice::create([
//             'blink_id' => $blinkInvoice['id'],
//             'payment_hash' => $blinkInvoice['payment_hash'],
//             'payment_request' => $blinkInvoice['payment_request'], // bolt11 string
//             'amount_msat' => $blinkInvoice['amount_msat'],
//             'status' => 'pending',
//             'full_name' => $fullName ?? 'No name',
//             'username' => $username ?? 'No username',
//             'telegram_client_ip' => $clientIp ?? 'No IP',
//             'is_instant_buy' => false,
//         ]);

//         Log::info("Invoice stored: {$invoice}");
        
//         // 4. Associate purchase with this invoice
//         Purchase::create([
//             'invoice_id' => $invoice->id,
//             'telegram_id' => $telegramUserId,
//         ]);
//         Log::info("Purchase record created for user {$telegramUserId} with invoice ID {$invoice->id}");

//         // 5. Send main message with payment button (Bitika link only)
//         $keyboard = [
//             'inline_keyboard' => [
//                 [
//                     ['text' => '💸 Pay via M-Pesa (Bitika)', 'url' => 'https://bitika.xyz']
//                 ]
//             ]
//         ];

//        $mainMessageText = sprintf(
//         "⚡️ *Pay %s sats to get your invite link.*\n\n"
//         . "💰 Amount: `%s sats`\n\n"
//         . "📌 *Instructions:*\n"
//         . "1️⃣ Click the button below to open Bitika\n"
//         . "2️⃣ Copy the invoice from the NEXT message\n"
//         . "3️⃣ Paste it in Bitika and complete payment via M-Pesa\n"
//         . "4️⃣ Wait a few seconds for confirmation\n\n"
//         . "⏳ Invoice expires in 10 minutes.",
//         $amountInSatoshis,
//         $amountInSatoshis
//         );

//         $this->telegram->sendMessage($chatId, $mainMessageText, $keyboard);
        
//         // 6. Send invoice as a SEPARATE message (only the invoice, no extra text)
//         $invoiceMessageText = sprintf("%s",$invoice->payment_request);

//         $this->telegram->sendMessage($chatId, $invoiceMessageText);

//     }

    protected function handlePurchaseCommand(string $chatId, string $telegramUserId, string $fullName, string $username, string $clientIp)
    {
        // 1. Check for existing pending invoices (Keep your existing logic)
        $existingPurchase = Purchase::where('telegram_id', $telegramUserId)
                                    ->whereHas('invoice', function($q) {
                                        $q->where('status', 'pending');
                                    })->first();

        if ($existingPurchase) {
            $expireInSeconds = (int) config('services.blink.invoice_expiry', 600);
            $expireDate = $existingPurchase->created_at->addSeconds($expireInSeconds);

            if (now()->greaterThan($expireDate)) {
                $existingPurchase->invoice->update(['status' => 'expired']);
            } else {
                $this->telegram->sendMessage($chatId, "⏳ You already have a pending payment. Please use /cancelInvoices if you want to start over.");
                return;
            }
        }

        // 2. Instead of creating invoice, ask for the TYPE of purchase
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 Instant Access (Higher Fee)', 'callback_data' => 'buy_type_instant'],
                    ['text' => '👥 Wait for Goal (Lower Fee)', 'callback_data' => 'buy_type_goal']
                ]
            ]
        ];

        $text = "📚 *Select your access type:*\n\n"
            . "🚀 *Instant:* Get the paper immediately after payment.\n"
            . "👥 *Wait for Goal:* Join the group; paper is released once member targets are met.";

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    protected function processInvoiceGeneration(string $chatId, string $telegramUserId, bool $isInstant, $fullName, $username, $clientIp)
    {
        // Decide amount based on choice
        $amount = $isInstant 
            ? (int) config('services.blink.instant_buy_amount', 500) 
            : (int) config('services.blink.invoice_amount', 100);

        $this->telegram->sendMessage($chatId, "⏳ Generating your " . ($isInstant ? "Instant" : "Goal-based") . " invoice...");

        $invoiceExpiry = config('services.blink.invoice_expiry', 600);
        $blinkInvoice = $this->blink->createInvoice($amount, $invoiceExpiry);

        if (!$blinkInvoice) {
            $this->telegram->sendMessage($chatId, "❌ Payment system error.");
            return;
        }

        // Store in DB
        $invoice = Invoice::create([
            'blink_id' => $blinkInvoice['id'],
            'payment_hash' => $blinkInvoice['payment_hash'],
            'payment_request' => $blinkInvoice['payment_request'],
            'amount_msat' => $blinkInvoice['amount_msat'],
            'status' => 'pending',
            'full_name' => $fullName ?? 'No name',
            'username' => $username ?? 'No username',
            'telegram_client_ip' => $clientIp ?? 'No IP',
            'is_instant_buy' => $isInstant, // Store the choice here
        ]);

        Purchase::create([
            'invoice_id' => $invoice->id,
            'telegram_id' => $telegramUserId,
        ]);

        // Send the payment instructions
        $keyboard = [
            'inline_keyboard' => [[['text' => '💸 Pay via M-Pesa (Bitika)', 'url' => 'https://bitika.xyz']]]
        ];

        $mainMessageText = sprintf(
            "Payment Instructions for the paper:*\n\n".
            "1️⃣ Click the button below to open Bitika\n".
            "2️⃣ Copy the invoice from the NEXT message(copy and paste only the code)\n".
            "3️⃣ Paste it in Bitika and complete payment via M-Pesa\n".
            "4️⃣ Wait a few seconds for confirmation\n\n".
            "⏳ Invoice expires in the next 24hrs"
        );

        $this->telegram->sendMessage($chatId, $mainMessageText, $keyboard);
        
        // Send the raw blink invoice for easy copying
        $this->telegram->sendMessage($chatId, $invoice->payment_request);
    }

}