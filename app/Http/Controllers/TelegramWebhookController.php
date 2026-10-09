<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Subject;
use App\Services\BitikaPaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegram,
        protected BitikaPaymentService $bitika
    ) {}

    /**
     * Handle incoming Telegram updates.
     */
    public function handle(Request $request)
    {
        $update = $request->all();

        Log::info('Telegram webhook received', [
            'update_id' => $update['update_id'] ?? null,
            'has_callback' => isset($update['callback_query']),
            'has_message' => isset($update['message']),
        ]);

        $clientIp = $request->ip();

        /*
         * 1. Handle inline keyboard callbacks.
         */
        if (isset($update['callback_query'])) {
            $callback = $update['callback_query'];

            $data = $callback['data'] ?? '';
            $chatId = $callback['message']['chat']['id'] ?? null;
            $telegramUserId = $callback['from']['id'] ?? null;

            if (!$chatId || !$telegramUserId) {
                return response()->json([
                    'status' => 'invalid_callback',
                ]);
            }

            $firstName = $callback['from']['first_name'] ?? '';
            $lastName = $callback['from']['last_name'] ?? '';

            $fullName = trim($firstName . ' ' . $lastName)
                ?: 'No name';

            $username = $callback['from']['username']
                ?? 'No username';

            Log::info('Telegram callback received', [
                'telegram_user_id' => $telegramUserId,
                'username' => $username,
                'callback_data' => $data,
            ]);

            if ($data === 'buy_type_instant') {
                $this->processInvoiceGeneration(
                    (string) $chatId,
                    (string) $telegramUserId,
                    true,
                    $fullName,
                    $username,
                    $clientIp
                );
            } elseif ($data === 'buy_type_goal') {
                $this->processInvoiceGeneration(
                    (string) $chatId,
                    (string) $telegramUserId,
                    false,
                    $fullName,
                    $username,
                    $clientIp
                );
            }

            // Answer the callback if TelegramService supports this method.
            // Uncomment if your TelegramService implements it:
            // $this->telegram->answerCallbackQuery($callback['id']);

            return response()->json(['status' => 'ok']);
        }

        /*
         * 2. Handle standard text messages.
         */
        if (!isset($update['message']['text'])) {
            Log::info('Ignoring unsupported Telegram update.');

            return response()->json(['status' => 'ok']);
        }

        $message = $update['message'];

        $chatId = $message['chat']['id'] ?? null;
        $telegramUserId = $message['from']['id'] ?? null;
        $text = trim((string) $message['text']);

        if (!$chatId || !$telegramUserId) {
            Log::warning('Telegram message missing chat or user ID.', [
                'message' => $message,
            ]);
            return response()->json([
                'status' => 'invalid_message',
            ]);
        }

        $chatId = (string) $chatId;
        $telegramUserId = (string) $telegramUserId;

        $firstName = $message['from']['first_name'] ?? '';
        $lastName = $message['from']['last_name'] ?? '';

        $fullName = trim($firstName . ' ' . $lastName)
            ?: 'No name';

        $username = $message['from']['username']
            ?? 'No username';

        $isOwner = $this->isBotOwner($telegramUserId);

        Log::info('Telegram message received', [
            'telegram_user_id' => $telegramUserId,
            'username' => $username,
            'text' => Str::startsWith($text, '/')
                ? $text
                : '[user message]',
        ]);

        /*
         * Handle commands before interpreting ordinary text as a phone
         * number. This allows /cancelInvoices to work while awaiting a
         * phone number.
         */
        if ($text === '/start' || $text === '/buy') {
            Log::info('Handling purchase command', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'command' => $text,
            ]);
            $this->handlePurchaseCommand(
                $chatId,
                $telegramUserId,
                $fullName,
                $username,
                $clientIp,
                $text
            );

            return response()->json(['status' => 'ok']);
        }

        if ($text === '/resetGroup' && $isOwner) {
            $this->handleResetGroup($chatId, $telegramUserId);

            return response()->json(['status' => 'ok']);
        }

        if ($text === '/cancelInvoices') {
            $this->cancelPendingInvoices($chatId, $telegramUserId);

            return response()->json(['status' => 'ok']);
        }

        /*
         * If the user has an invoice awaiting their M-Pesa number,
         * treat their next ordinary text message as the phone number.
         */
        if (!Str::startsWith($text, '/')) {
            Log::info('Processing phone number input', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'phone_number' => $text,
            ]);

            $waitingPurchase = Purchase::where(
                'telegram_id',
                $telegramUserId
            )
                ->whereHas('invoice', function ($query) {
                    $query->where('payment_provider', 'bitika')
                        ->where('status', 'awaiting_phone');
                })
                ->with('invoice')
                ->first();

            if ($waitingPurchase && $waitingPurchase->invoice) {
                Log::info('Found invoice awaiting phone number.', [
                    'invoice_id' => $waitingPurchase->invoice->id,
                    'telegram_user_id' => $telegramUserId,
                ]);
                $this->initiateBitikaPayment(
                    $chatId,
                    $waitingPurchase->invoice,
                    $text
                );

                return response()->json(['status' => 'ok']);
            }
        }

        $this->telegram->sendMessage(
            $chatId,
            '🤖 Use /start to view the available purchase options.'
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Start the purchase flow.
     */
    protected function handlePurchaseCommand(
        string $chatId,
        string $telegramUserId,
        string $fullName,
        string $username,
        ?string $clientIp,
        ?string $command = null
    ): void {
        Log::info('Handling purchase command', [
            'chat_id' => $chatId,
            'telegram_user_id' => $telegramUserId,
            'command' => $command,
        ]);
        $existingPurchase = Purchase::where(
            'telegram_id',
            $telegramUserId
        )
            ->whereHas('invoice', function ($query) {
                $query->whereIn('status', [
                    'awaiting_phone',
                    'processing',
                    'pending',
                ]);
            })
            ->with('invoice')
            ->first();

        if ($existingPurchase) {
            Log::info('Found existing purchase.', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'invoice_id' => $existingPurchase->invoice->id,
            ]);
            $invoice = $existingPurchase->invoice;

            if ($invoice && $invoice->status === 'awaiting_phone') {
                $this->telegram->sendMessage(
                    $chatId,
                    'You already have a purchase awaiting your M-Pesa '
                    . "number.\n\n"
                    . 'Enter the number that should receive the STK Push, '
                    . 'or send /cancelInvoices to cancel the request.'
                );

                return;
            }

            if ($invoice && $invoice->status === 'processing') {
                Log::info('Purchase is currently processing.', [
                    'chat_id' => $chatId,
                    'telegram_user_id' => $telegramUserId,
                    'invoice_id' => $invoice->id,
                ]);
                $this->telegram->sendMessage(
                    $chatId,
                    'Your M-Pesa payment request is being processed. '
                    . 'Please check your phone and wait for confirmation '
                    . 'before starting another purchase.'
                );

                return;
            }

            if ($invoice && $invoice->status === 'pending') {
                Log::info('Purchase is pending payment.', [
                    'chat_id' => $chatId,
                    'telegram_user_id' => $telegramUserId,
                    'invoice_id' => $invoice->id,
                ]);
                $expirySeconds = (int) config(
                    'services.blink.invoice_expiry',
                    600
                );

                $expiryDate = $existingPurchase->created_at
                    ->copy()
                    ->addSeconds($expirySeconds);

                if (now()->greaterThan($expiryDate)) {
                    Log::info('Pending invoice has expired.', [
                        'chat_id' => $chatId,
                        'telegram_user_id' => $telegramUserId,
                        'invoice_id' => $invoice->id,
                    ]);
                    $invoice->update([
                        'status' => Invoice::STATUS_EXPIRED,
                    ]);
                } else {
                    Log::info('Pending invoice is still valid.', [
                        'chat_id' => $chatId,
                        'telegram_user_id' => $telegramUserId,
                        'invoice_id' => $invoice->id,
                    ]);
                    $this->telegram->sendMessage(
                        $chatId,
                        'You already have a pending payment. '
                        . 'Use /cancelInvoices if you want to cancel '
                        . 'the request before starting another.'
                    );

                    return;
                }
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'Generate invoice for Instant Access',
                        'callback_data' => 'buy_type_instant',
                    ],
                ],
                // Add the goal-based option if you want it displayed:
                [
                    [
                        'text' => 'Pay to Access VIP Channel',
                        'callback_data' => 'buy_type_goal',
                    ],
                ],
            ],
        ];

        $message = "1. The amount for fisheries is 7,500 KES.\n"
            . "2. Select the option below to initiate payment.\n"
            . "3. Enter the M-Pesa number that should receive the STK Push.\n"
            . "4. Your materials will be delivered after payment "
            . "confirmation.\n\n"
            . 'Only make payments through the bot instructions.';

        $this->telegram->sendMessage(
            $chatId,
            $message,
            $keyboard
        );
    }

    /**
     * Create the invoice and purchase before asking for a phone number.
     */
    protected function processInvoiceGeneration(
        string $chatId,
        string $telegramUserId,
        bool $isInstant,
        string $fullName,
        string $username,
        ?string $clientIp
    ): void {
        Log::info('Processing invoice generation', [
            'chat_id' => $chatId,
            'telegram_user_id' => $telegramUserId,
            'is_instant' => $isInstant,
        ]);

        $existingPurchase = Purchase::where(
            'telegram_id',
            $telegramUserId
        )
            ->whereHas('invoice', function ($query) {
                $query->whereIn('status', [
                    'awaiting_phone',
                    'processing',
                    'pending',
                ]);
            })
            ->first();

        if ($existingPurchase) {
            Log::info('User already has an active purchase.', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'invoice_id' => $existingPurchase->invoice->id,
            ]);
            $this->telegram->sendMessage(
                $chatId,
                'You already have an active purchase. '
                . 'Please complete or cancel it before starting another.'
            );

            return;
        }

        $amount = $this->getPurchaseAmount($isInstant);

        if ($amount <= 0) {
            Log::error('Invalid configured purchase amount.', [
                'instant_buy' => $isInstant,
                'amount' => $amount,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Unable to start a purchase right now. Please try again later.'
            );

            return;
        }

        try {
            $invoice = Invoice::create([
                'status' => 'awaiting_phone',
                'payment_provider' => 'bitika',
                'amount_kes' => $amount,
                'full_name' => $fullName,
                'username' => $username,
                'telegram_client_ip' => $clientIp,
                'is_instant_buy' => $isInstant,
            ]);

            Purchase::create([
                'invoice_id' => $invoice->id,
                'telegram_id' => $telegramUserId,
            ]);
        } catch (Throwable $e) {
            Log::error('Could not create Telegram purchase.', [
                'telegram_user_id' => $telegramUserId,
                'error' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Unable to create your purchase right now. '
                . 'Please try again later.'
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "💳 *M-Pesa Payment*\n\n"
            . 'Amount: KES ' . number_format($amount) . "\n\n"
            . 'Enter the Safaricom M-Pesa number that should receive '
            . "the STK Push.\n\n"
            . "Example: 0712345678\n\n"
            . 'Send /cancelInvoices to cancel this request.',
            ['parse_mode' => 'Markdown']
        );
    }

    /**
     * Initiate a Bitika STK Push for an invoice.
     */
    protected function initiateBitikaPayment(
        string $chatId,
        Invoice $invoice,
        string $phone
    ): void {
        Log::info('Initiating Bitika payment', [
            'chat_id' => $chatId,
            'invoice_id' => $invoice->id,
            'phone_number' => $phone,
        ]);
        if ($invoice->status !== 'awaiting_phone') {
            Log::warning('Invoice is not awaiting a phone number.', [
                'invoice_id' => $invoice->id,
                'current_status' => $invoice->status,
            ]);
            $this->telegram->sendMessage(
                $chatId,
                'This payment request is no longer awaiting a phone number.'
            );

            return;
        }

        try {

            $phone = $this->formatPhoneNumber($phone);
        } catch (\InvalidArgumentException $e) {
            $this->telegram->sendMessage(
                $chatId,
                'Invalid Kenyan mobile number. Enter a number such as '
                . '0712345678 or 254712345678.'
            );

            return;
        }

        $amount = (int) $invoice->amount_kes;

        if ($amount <= 0) {
            Log::error('Invoice has an invalid KES amount.', [
                'invoice_id' => $invoice->id,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Unable to start payment for this invoice. '
                . 'Please contact support.'
            );

            return;
        }

        /*
         * Persist a single payment attempt before making the external
         * request. This also prevents a duplicate Telegram update from
         * creating a second STK Push for the same invoice.
         */
        $idempotencyKey = (string) Str::uuid();

        $updated = Invoice::whereKey($invoice->id)
            ->where('status', 'awaiting_phone')
            ->update([
                'mpesa_phone_number' => $phone,
                'idempotency_key' => $idempotencyKey,
                'payment_initiated_at' => now(),
                'payment_error' => null,
                'status' => 'processing',
            ]);

        if (!$updated) {
            Log::warning('Invoice status changed before payment initiation.', [
                'invoice_id' => $invoice->id,
                'current_status' => $invoice->status,
            ]);
            $this->telegram->sendMessage(
                $chatId,
                'This payment request has already been submitted. '
                . 'Please wait for its status to be confirmed.'
            );

            return;
        }

        try {
            Log::info('Sending STK Push request to Bitika.', [
                'invoice_id' => $invoice->id,
                'phone_number' => $phone,
                'amount_kes' => $amount,
                'idempotency_key' => $idempotencyKey,
            ]);
            $response = $this->bitika->collect(
                $phone,
                $amount,
                $idempotencyKey
            );

            $data = $response['response'] ?? $response;

            $status = strtolower((string) ($data['status'] ?? ''));

            $transactionCode = $data['transaction_code']
                ?? $data['transactionCode']
                ?? null;

            /*
             * Confirm the exact status and field names against the
             * response returned by your BitikaPaymentService.
             */
            $acceptedStatuses = ['processing', 'pending'];

            if (
                !$transactionCode
                || !in_array($status, $acceptedStatuses, true)
            ) {
                $invoice->update([
                    'status' => 'failed',
                    'payment_error' => 'Bitika did not confirm STK initiation.',
                    'payment_metadata' => $data,
                ]);

                Log::warning('Bitika did not accept the payment request.', [
                    'invoice_id' => $invoice->id,
                    'response' => $data,
                ]);

                $this->telegram->sendMessage(
                    $chatId,
                    'Unable to confirm that the STK Push was initiated. '
                    . 'Please contact support before attempting another payment.'
                );

                return;
            }

            $invoice->update([
                'bitika_transaction_code' => $transactionCode,
                'payment_metadata' => $data,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "📲 *STK Push requested*\n\n"
                . "Phone: {$phone}\n"
                . 'Amount: KES ' . number_format($amount) . "\n"
                . "Reference: {$transactionCode}\n\n"
                . 'Check your phone and follow the M-Pesa prompt. '
                . 'Your purchase will be delivered after payment is confirmed.',
                ['parse_mode' => 'Markdown']
            );
        } catch (Throwable $e) {
            /*
             * A timeout does not necessarily mean Bitika failed to
             * receive the request. Keep the invoice processing to
             * avoid accidentally initiating a duplicate payment.
             * Reconcile it using Bitika's transaction-status API.
             */
            $invoice->update([
                'payment_error' => 'Could not confirm the STK initiation response.',
            ]);

            Log::error('Bitika STK initiation response was uncertain.', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'We could not confirm the payment request status. '
                . 'Please do not retry the payment yet. '
                . 'Contact support so the transaction can be checked.'
            );
        }
    }

    /**
     * Return the configured amount for the selected purchase type.
     *
     * Bitika amounts are in KES, not millisatoshis.
     */
    protected function getPurchaseAmount(bool $isInstant): int
    {
        if ($isInstant) {
            return (int) config(
                'services.bitika.instant_buy_amount',
                config('services.blink.instant_buy_amount', 7500)
            );
        }

        return (int) config(
            'services.bitika.invoice_amount',
            config('services.blink.invoice_amount', 100)
        );
    }

    /**
     * Normalize a Kenyan mobile number to 254XXXXXXXXX.
     */
    protected function formatPhoneNumber(string $phone): string
    {
        $phone = trim($phone);

        // Allow common formatting characters such as spaces and hyphens.
        $cleaned = preg_replace('/[\s().-]+/', '', $phone);

        if (!is_string($cleaned)) {
            throw new \InvalidArgumentException(
                'Invalid Kenyan mobile phone number.'
            );
        }

        if (!preg_match('/^(?:\+?254|0)?[71]\d{8}$/', $cleaned)) {
            throw new \InvalidArgumentException(
                'Invalid Kenyan mobile phone number.'
            );
        }

        $digits = preg_replace('/\D/', '', $cleaned);

        if (!is_string($digits)) {
            throw new \InvalidArgumentException(
                'Invalid Kenyan mobile phone number.'
            );
        }

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }

        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        if (
            strlen($digits) === 9
            && (
                str_starts_with($digits, '7')
                || str_starts_with($digits, '1')
            )
        ) {
            return '254' . $digits;
        }

        throw new \InvalidArgumentException(
            'Invalid Kenyan mobile phone number.'
        );
    }

    /**
     * Cancel invoices that have not yet entered payment processing.
     *
     * A processing STK Push is deliberately not cancelled locally:
     * Bitika may already have sent it to the customer's phone.
     */
    protected function cancelPendingInvoices(
        string $chatId,
        string $telegramUserId
    ): void {
        $purchases = Purchase::where('telegram_id', $telegramUserId)
            ->whereHas('invoice', function ($query) {
                $query->whereIn('status', [
                    'awaiting_phone',
                    'pending',
                ]);
            })
            ->with('invoice')
            ->get();

        if ($purchases->isEmpty()) {
            $processingPurchase = Purchase::where(
                'telegram_id',
                $telegramUserId
            )
                ->whereHas('invoice', function ($query) {
                    $query->where('status', 'processing');
                })
                ->exists();

            $this->telegram->sendMessage(
                $chatId,
                $processingPurchase
                    ? 'Your STK Push may already be active. '
                        . 'It cannot be safely cancelled here; '
                        . 'please wait for confirmation or contact support.'
                    : 'You have no cancellable payment requests.'
            );

            return;
        }

        foreach ($purchases as $purchase) {
            $invoice = $purchase->invoice;

            if (
                $invoice
                && in_array($invoice->status, [
                    'awaiting_phone',
                    'pending',
                ], true)
            ) {
                $invoice->update([
                    'status' => Invoice::STATUS_CANCELLED,
                ]);

                Log::info('Invoice cancelled by Telegram user.', [
                    'invoice_id' => $invoice->id,
                    'telegram_user_id' => $telegramUserId,
                ]);
            }
        }

        $this->telegram->sendMessage(
            $chatId,
            'Your cancellable payment requests have been cancelled.'
        );
    }

    /**
     * Check whether a Telegram user is the configured bot owner.
     */
    protected function isBotOwner(string $telegramUserId): bool
    {
        $ownerId = config('services.telegram.owner_id');

        return $ownerId !== null
            && (string) $ownerId === $telegramUserId;
    }

    /**
     * Reset the configured Telegram group, retaining administrators.
     */
    protected function handleResetGroup(
        string $chatId,
        string $telegramUserId
    ): void {
        Log::info('Group reset command initiated.', [
            'telegram_user_id' => $telegramUserId,
        ]);

        $this->telegram->sendMessage(
            $chatId,
            '🔄 Starting group reset... This may take a few moments.'
        );

        try {
            $groupChatId = config('services.telegram.channel_id');

            if (!$groupChatId) {
                $this->telegram->sendMessage(
                    $chatId,
                    '❌ The Telegram group ID is not configured.'
                );

                return;
            }

            $members = $this->telegram->getChatMembers($groupChatId);

            if (!is_array($members) || empty($members)) {
                Log::error('Unable to retrieve Telegram group members.', [
                    'group_chat_id' => $groupChatId,
                ]);

                $this->telegram->sendMessage(
                    $chatId,
                    '❌ Unable to fetch group members. '
                    . 'Confirm that the bot has the required permissions.'
                );

                return;
            }

            $totalMembers = count($members);
            $removedCount = 0;
            $adminCount = 0;
            $failedCount = 0;

            $this->telegram->sendMessage(
                $chatId,
                "📊 Found {$totalMembers} members. "
                . 'Removing non-admin users...'
            );

            foreach ($members as $member) {
                $user = $member['user'] ?? [];
                $userId = $user['id'] ?? null;
                $status = $member['status'] ?? '';

                if (!$userId) {
                    $failedCount++;
                    continue;
                }

                $userName = $user['username']
                    ?? $user['first_name']
                    ?? 'Unknown';

                if (in_array($status, ['administrator', 'creator'], true)) {
                    $adminCount++;
                    continue;
                }

                // Avoid removing the bot itself.
                if (
                    (string) $userId
                    === (string) config('services.telegram.bot_user_id')
                ) {
                    continue;
                }

                $removed = $this->telegram->kickChatMember(
                    $groupChatId,
                    $userId
                );

                if ($removed) {
                    $removedCount++;

                    Log::info('Removed Telegram group member.', [
                        'user_id' => $userId,
                        'username' => $userName,
                    ]);
                } else {
                    $failedCount++;

                    Log::warning('Could not remove Telegram group member.', [
                        'user_id' => $userId,
                        'username' => $userName,
                    ]);
                }

                // Small delay to reduce the risk of hitting API limits.
                usleep(50000);
            }

            $summary = "✅ *Group Reset Complete!*\n\n"
                . "📊 *Statistics:*\n"
                . "├ Total members: {$totalMembers}\n"
                . "├ 👑 Admins kept: {$adminCount}\n"
                . "├ 🗑️ Removed users: {$removedCount}\n"
                . "└ ❌ Failed removals: {$failedCount}\n\n"
                . '🔒 Group reset finished.';

            $this->telegram->sendMessage(
                $chatId,
                $summary,
                ['parse_mode' => 'Markdown']
            );

            Log::info('Telegram group reset completed.', [
                'total' => $totalMembers,
                'removed' => $removedCount,
                'admins_kept' => $adminCount,
                'failed' => $failedCount,
            ]);
        } catch (Throwable $e) {
            Log::error('Error during Telegram group reset.', [
                'telegram_user_id' => $telegramUserId,
                'error' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                '❌ An error occurred during the group reset. '
                . 'Check the application logs for details.'
            );
        }
    }
}
