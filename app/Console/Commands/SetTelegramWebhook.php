<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebhook extends Command
{
    /**
     * Updated signature to include --info and --remove options
     */
    protected $signature = 'telegram:manage-bot 
                            {--remove : Remove the webhook} 
                            {--info= : Get ID and details of a @username (Channel or Group)}';
    
    protected $description = 'Manage Telegram Bot: Set/Remove webhooks or retrieve chat info';

    public function handle()
    {
        $token = config('services.telegram.bot_token');
        
        if (!$token) {
            $this->error('TELEGRAM_BOT_TOKEN not set in .env file');
            return 1;
        }

        $baseUrl = "https://api.telegram.org/bot{$token}/";

        // OPTION 1: Get Chat Info
        if ($this->option('info')) {
            return $this->getChatInfo($baseUrl, $this->option('info'));
        }

        // OPTION 2: Remove Webhook
        if ($this->option('remove')) {
            return $this->removeWebhook($baseUrl);
        }

        // OPTION 3: Set Webhook (Default)
        return $this->setWebhook($baseUrl);
    }

    protected function getChatInfo($baseUrl, $username)
    {
        $this->info("🔍 Fetching info for {$username}...");
        
        $response = Http::get($baseUrl . 'getChat', ['chat_id' => $username]);
        $result = $response->json();

        if ($response->successful() && $result['ok']) {
            $chat = $result['result'];
            $this->table(
                ['Field', 'Details'],
                [
                    ['Title', $chat['title'] ?? 'N/A'],
                    ['ID', $chat['id']],
                    ['Type', $chat['type']],
                    ['Username', $chat['username'] ?? 'None'],
                    ['Bio/Desc', $chat['description'] ?? 'N/A'],
                ]
            );
            $this->info("💡 Use the ID above in your .env or services config.");
        } else {
            $this->error("❌ Error: " . ($result['description'] ?? 'Chat not found.'));
            $this->line("Ensure the bot is an ADMIN in the channel and the @username is correct.");
        }
        return 0;
    }

    protected function removeWebhook($baseUrl)
    {
        $response = Http::post($baseUrl . 'deleteWebhook', ['drop_pending_updates' => true]);
        
        if ($response->json('ok')) {
            $this->info('✅ Webhook removed successfully');
        } else {
            $this->error('Failed to remove: ' . $response->json('description'));
        }
        return 0;
    }

    protected function setWebhook($baseUrl)
    {
        $webhookUrl = config('app.url') . '/api/telegram/webhook';
        $secretToken = config('services.telegram.webhook_secret');

        $response = Http::post($baseUrl . 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query', 'chat_member'],
            'drop_pending_updates' => true,
            'max_connections' => 40,
        ]);

        if ($response->json('ok')) {
            $this->info('✅ Webhook set successfully!');
            $this->line("URL: {$webhookUrl}");
            
            $info = Http::get($baseUrl . 'getWebhookInfo')->json();
            $this->line("\n📡 Status: " . ($info['result']['url'] ?? 'Disconnected'));
        } else {
            $this->error('Failed: ' . $response->json('description'));
            return 1;
        }
        return 0;
    }
}