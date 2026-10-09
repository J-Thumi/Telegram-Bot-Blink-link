<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Subject;
use Illuminate\Support\Facades\Log;

class PurchaseFulfillmentService
{
    public function __construct(
        protected TelegramService $telegram
    ) {}

    public function fulfill(Invoice $invoice): void
    {
        $purchase = Purchase::where('invoice_id', $invoice->id)
            ->first();

        if (!$purchase) {
            Log::error('Paid invoice has no associated purchase.', [
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        $telegramUserId = (string) $purchase->telegram_id;

        if ($invoice->is_instant_buy) {
            $this->telegram->sendMessage(
                $telegramUserId,
                'Payment received! Your papers will be sent once '
                . 'processing is completed. This may take a few moments...'
            );

            $subject = Subject::getNextDue();

            $purchase->update([
                'subject' => $subject?->name,
            ]);

            if (!$subject) {
                Log::critical(
                    'Instant purchase paid, but no subject is due.',
                    [
                        'telegram_user_id' => $telegramUserId,
                        'invoice_id' => $invoice->id,
                    ]
                );
            }

            return;
        }

        $subject = Subject::getNextDue();

        $purchase->update([
            'subject' => $subject?->name,
        ]);

        $invite = $this->telegram->createSingleUseInviteLink(
            $telegramUserId
        );

        $inviteLink = $invite['invite_link'] ?? null;

        if (!$inviteLink) {
            Log::error('Could not create Telegram invite link.', [
                'invoice_id' => $invoice->id,
                'telegram_user_id' => $telegramUserId,
            ]);

            return;
        }

        $purchase->update([
            'telegram_invite_link' => $inviteLink,
            'invite_sent_at' => now(),
        ]);

        $this->telegram->sendMessage(
            $telegramUserId,
            "✅ *Payment Confirmed!*\n\n"
            . "Join the VIP Channel: {$inviteLink}",
            ['parse_mode' => 'Markdown']
        );

        Log::info('VIP Channel purchase fulfilled.', [
            'invoice_id' => $invoice->id,
            'telegram_user_id' => $telegramUserId,
        ]);
    }
}
