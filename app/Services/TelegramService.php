<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $baseUrl;
    protected string $token;
    protected string $channelID;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}/";
        $this->channelID = config('services.telegram.channel_id');
    }

    /**
     * Send a plain text message to a Telegram user.
     */
   public function sendMessage(string $chatId, string $text, $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        // If a keyboard is provided, add it to the payload
        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $response = Http::post($this->baseUrl . 'sendMessage', $payload);
        
        if (!$response->successful()) {
            Log::error('Telegram sendMessage failed', [
                'chat_id' => $chatId,
                'response' => $response->json()
            ]);
        }

        return $response->json();
    }

// App\Services\TelegramService

public function sendPhoto(string $chatId, string $photo, ?string $caption = null): array
{
    $url = $this->baseUrl . 'sendPhoto';
    $params = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];

    // Check if $photo is a local path or a remote URL
    if (file_exists($photo)) {
        $response = Http::attach('photo', file_get_contents($photo), basename($photo))
            ->post($url, $params);
    } else {
        $params['photo'] = $photo;
        $response = Http::post($url, $params);
    }

    if (!$response->successful()) {
        Log::error('Telegram sendPhoto failed', [
            'chat_id' => $chatId,
            'response' => $response->json()
        ]);
    }

    return $response->json();
}

/**
 * Core helper to handle sending a subject's images to any chatId (User or Channel)
 */
public function fulfillSubjectImages(string $chatId, \App\Models\Subject $subject): bool
{
    $images = $subject->images()->orderBy('sort_order')->get();
    if ($images->isEmpty()) return false;

    $mainCaption = "<b>{$subject->name}</b>\n\n" . ($subject->description ?? '');
    $success = false;

    foreach ($images as $index => $image) {
        // Only the first image gets the full caption
        $caption = ($index === 0) ? $mainCaption : null;
        
        $path = $this->resolveImagePath($image->path);
        
        if ($path) {
            $res = $this->sendPhoto($chatId, $path, $caption);
            if ($res['ok'] ?? false) $success = true;
        }
    }

    return $success;
}

/**
 * Centralized path resolver to find images across various Laravel directories
 */
private function resolveImagePath(string $path): ?string
{
    $possiblePaths = [
        storage_path("app/public/{$path}"),
        storage_path("app/{$path}"),
        public_path("storage/{$path}"),
        public_path($path),
        $path
    ];

    foreach ($possiblePaths as $testPath) {
        if (file_exists($testPath)) return $testPath;
    }

    return null;
}
    public function sendMediaGroup(string $chatId, array $imageUrls, string $caption = '')
    {
        $media = [];
        foreach ($imageUrls as $index => $url) {
            $media[] = [
                'type' => 'photo',
                'media' => $url,
                // Only add the caption to the first image in the group
                'caption' => ($index === 0) ? $caption : '',
                'parse_mode' => 'Markdown'
            ];
        }

        return Http::post($this->baseUrl . 'sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($media),
        ]);
    }
    /**
     * Create a single-use invite link for your private channel.
     * https://core.telegram.org/bots/api#createchatinvitelink
     */
    public function createSingleUseInviteLink(int $userId, int $expireInSeconds = 604800): ?array
    {
        $expireDate = now()->addSeconds($expireInSeconds)->timestamp;

        $response = Http::post($this->baseUrl . 'createChatInviteLink', [
            'chat_id' => $this->channelID,
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
            'chat_id' => $this->channelID,
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


    /**
     * Get all members of a chat/group
     * Note: This requires the bot to be admin
     */
    public function getChatMembers(string $chatId): ?array
    {
        Log::info("Fetching chat members for chat ID: {$chatId}");
        $members = [];
        $nextOffset = null;
        
        try {
            // Telegram API limits to 200 members per request, need to loop
            do {
                $params = [
                    'chat_id' => $chatId,
                    'limit' => 200
                ];
                
                if ($nextOffset) {
                    $params['offset'] = $nextOffset;
                }
                
                $response = Http::get($this->baseUrl . 'getChatAdministrators', [
                    'chat_id' => $chatId
                ]);
                
                $result = $response->json();
                
                if (isset($result['ok']) && $result['ok'] === true) {
                    // For supergroups, we also need regular members
                    $admins = $result['result'];
                    
                    // Get regular members (this is more complex and may require getChatMembersCount)
                    // For now, we'll work with admins + what we can get
                    $members = array_merge($members, $admins);
                }
                
                break; // For now, break after first iteration
                
            } while ($nextOffset);
            
            // Alternative: Use getChatMembersCount if you need all members
            // Note: Telegram doesn't provide a direct API to get all members of a large group
            
            return $members;
            
        } catch (\Exception $e) {
            Log::error('Failed to get chat members', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get chat administrators
     */
    public function getChatAdministrators(string $chatId): ?array
    {
        $response = Http::get($this->baseUrl . 'getChatAdministrators', [
            'chat_id' => $chatId
        ]);
        
        $result = $response->json();
        
        if (isset($result['ok']) && $result['ok'] === true) {
            return $result['result'];
        }
        
        Log::error('Failed to get chat administrators', [
            'chat_id' => $chatId,
            'response' => $result
        ]);
        
        return null;
    }

    /**
 * Get the total number of members in a chat.
 */
    public function getChatMemberCount(string $chatId): int
    {
        try {
            $response = Http::get($this->baseUrl . 'getChatMemberCount', [
                'chat_id' => $chatId
            ]);

            $result = $response->json();

            if (isset($result['ok']) && $result['ok'] === true) {
                return (int) $result['result'];
            }

            Log::error("Telegram API Error in getChatMemberCount", $result);
            return 0;
        } catch (\Exception $e) {
            Log::error('Failed to get chat member count', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    /**
     * Kick a member from the chat
     */
    public function kickChatMember(string $chatId, int $userId): bool
    {
        $response = Http::post($this->baseUrl . 'banChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
        
        $result = $response->json();
        
        if (isset($result['ok']) && $result['ok'] === true) {
            // Optionally unban immediately to allow rejoining later
            Http::post($this->baseUrl . 'unbanChatMember', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'only_if_banned' => true
            ]);
            return true;
        }
        
        Log::error('Failed to kick member', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'response' => $result
        ]);
        
        return false;
    }

    /**
     * Get bot owner ID from config
     */
    public function getBotOwnerId(): ?string
    {
        return config('services.telegram.owner_id');
    }



}
