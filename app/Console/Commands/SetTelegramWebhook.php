<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {--remove : Remove the webhook instead of setting it}';
    protected $description = 'Set or remove the Telegram bot webhook URL';

    public function handle()
    {
        $token = config('services.telegram.bot_token');
        
        if (!$token) {
            $this->error('TELEGRAM_BOT_TOKEN not set in .env file');
            return 1;
        }

        $baseUrl = "https://api.telegram.org/bot{$token}/";

        if ($this->option('remove')) {
            $response = Http::post($baseUrl . 'deleteWebhook', [
                'drop_pending_updates' => true
            ]);
            
            if ($response->json('ok')) {
                $this->info('✅ Webhook removed successfully');
            } else {
                $this->error('Failed to remove webhook: ' . $response->json('description'));
            }
            return 0;
        }

        // Set the webhook
        $webhookUrl = config('app.url') . '/api/telegram/webhook';
        $secretToken = config('services.telegram.webhook_secret');

        $response = Http::post($baseUrl . 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query'], // Only receive what we need
            'drop_pending_updates' => true,
            'max_connections' => 40, // Adjust based on your server capacity[citation:4]
        ]);

        if ($response->json('ok')) {
            $this->info('✅ Webhook set successfully!');
            $this->line("URL: {$webhookUrl}");
            $this->line("Secret Token: {$secretToken}");
            
            // Verify it's working
            $info = Http::get($baseUrl . 'getWebhookInfo')->json();
            $this->line("\n📡 Webhook Info:");
            $this->line("  URL: " . ($info['result']['url'] ?? 'Not set'));
            $this->line("  Pending Updates: " . ($info['result']['pending_update_count'] ?? 0));
        } else {
            $this->error('Failed to set webhook: ' . $response->json('description'));
            return 1;
        }

        return 0;
    }
}