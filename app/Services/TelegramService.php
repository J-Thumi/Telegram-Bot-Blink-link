<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $baseUrl;
    protected string $token;
    protected string $channelUsername;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}/";
        $this->channelUsername = config('services.telegram.channel_username');
    }

    /**
     * Send a plain text message to a Telegram user.
     */
    public function sendMessage(string $chatId, string $text, array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        $response = Http::post($this->baseUrl . 'sendMessage', $payload);
        return $response->json();
    }

    /**
 * Send a photo message to a Telegram user
 */
    public function sendPhoto(string $chatId, string $photoUrl, string $caption = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'parse_mode' => 'HTML',
        ];
        
        if ($caption) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'Markdown';
        }
        
        $response = Http::post($this->baseUrl . 'sendPhoto', $payload);
        
        if (!$response->successful()) {
            Log::error('Failed to send photo', [
                'chat_id' => $chatId,
                'response' => $response->json()
            ]);
        }
        
        return $response->json();
    }
    /**
     * Create a single-use invite link for your private channel.
     * https://core.telegram.org/bots/api#createchatinvitelink
     */
    public function createSingleUseInviteLink(int $userId, int $expireInSeconds = 3600): ?array
    {
        $expireDate = now()->addSeconds($expireInSeconds)->timestamp;

        $response = Http::post($this->baseUrl . 'createChatInviteLink', [
            'chat_id' => $this->channelUsername,
            'member_limit' => 1,       // Single-use link
            'expire_date' => $expireDate,
            'creates_join_request' => false,
        ]);

        $result = $response->json();

        if (isset($result['ok']) && $result['ok'] === true) {
            Log::info("Single-use invite link created for user {$userId}", $result['result']);
            return $result['result']; // Contains 'invite_link' and 'creator_id', etc.
        }

        Log::error("Failed to create invite link for user {$userId}", $result);
        return null;
    }

    /**
     * Revoke an invite link (optional but good for cleanup).
     */
    public function revokeInviteLink(string $inviteLinkId): bool
    {
        $response = Http::post($this->baseUrl . 'revokeChatInviteLink', [
            'chat_id' => $this->channelUsername,
            'invite_link_id' => $inviteLinkId,
        ]);

        $result = $response->json();
        return ($result['ok'] ?? false) === true;
    }

    /**
     * Set the bot's webhook URL.
     * Run this once via a command or tinker.
     */
    public function setWebhook(string $url): array
    {
        $response = Http::post($this->baseUrl . 'setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
        ]);

        return $response->json();
    }
}