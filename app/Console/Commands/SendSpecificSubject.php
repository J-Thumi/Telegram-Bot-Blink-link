<?php

namespace App\Console\Commands;

use App\Models\Subject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendSpecificSubject extends Command
{
    protected $signature = 'subjects:send-specific
                            {subject_id : The ID of the subject to send}
                            {--channel-id= : Telegram channel ID (overrides .env)}
                            {--bot-token= : Telegram bot token (overrides .env)}';

    protected $description = 'Send a specific subject\'s images to Telegram directly without checks';

    public function handle()
    {
        $subjectId = $this->argument('subject_id');
        
        $subject = Subject::with('images')->find($subjectId);
        
        if (!$subject) {
            $this->error("❌ Subject with ID {$subjectId} not found!");
            return Command::FAILURE;
        }
        
        $this->info("\n📦 Processing subject: {$subject->name}");
        $this->line(str_repeat('─', 50));
        
        $channelId = $this->option('channel-id') ?? config('services.telegram.channel_id');
        $botToken = $this->option('bot-token') ?? config('services.telegram.bot_token');
        
        if (!$channelId || !$botToken) {
            $this->error("❌ Telegram configuration missing! Check TELEGRAM_BOT_TOKEN and TELEGRAM_CHANNEL_ID in .env");
            return Command::FAILURE;
        }
        
        // Check if subject has images
        $hasImages = $subject->images()->exists();
        
        if (!$hasImages) {
            $this->error("❌ No images found for this subject!");
            Log::error('No images found for subject', ['subject' => $subject->id]);
            return Command::FAILURE;
        }
        
        $imageCount = $subject->images()->count();
        $this->info("📸 Found {$imageCount} image(s) for this subject");
        
        // Send images directly
        $this->info("🚀 Sending images to Telegram...");
        $this->line("");
        
        $success = $this->sendImages($subject, $channelId, $botToken);
        
        if ($success) {
            $subject->update([
                'images_sent_at' => now(),
                'status' => 'sent',
                'last_checked_at' => now()
            ]);
            
            $this->line("");
            $this->info("✅ All images sent successfully!");
            $this->line(str_repeat('─', 50));
            
            Log::info('Subject images sent manually', [
                'subject' => $subject->id,
                'subject_name' => $subject->name,
                'images_count' => $imageCount
            ]);
            
            return Command::SUCCESS;
        } else {
            $subject->update(['status' => 'failed']);
            $this->error("❌ Failed to send some or all images!");
            return Command::FAILURE;
        }
    }
    
    private function sendImages($subject, $channelId, $botToken)
    {
        $images = $subject->images()->orderBy('sort_order')->get();
        $successCount = 0;
        $totalCount = $images->count();
        
        $caption = "<b>📚 {$subject->name}</b>\n\n";
        if ($subject->description) {
            $caption .= $subject->description . "\n\n";
        }
        if ($subject->due_date) {
            $caption .= "📅 Due: " . Carbon::parse($subject->due_date)->format('F j, Y');
        }
        
        foreach ($images as $index => $image) {
            $imageCaption = $index === 0 ? $caption : null;
            $current = $index + 1;
            
            $this->line("  📤 Sending image {$current}/{$totalCount}: {$image->title}");
            
            if ($this->sendSingleImage($image, $channelId, $botToken, $imageCaption)) {
                $successCount++;
                $this->line("  ✅ Image {$current}/{$totalCount} sent successfully");
            } else {
                $this->error("  ❌ Failed to send image: {$image->title}");
            }
            
            // Small delay between messages to avoid rate limiting
            if ($index < $totalCount - 1) {
                usleep(300000); // 0.3 second delay
            }
        }
        
        return $successCount === $totalCount;
    }
    
    private function sendSingleImage($image, $channelId, $botToken, $caption = null)
    {
        try {
            // Try different path resolutions
            $imagePath = $this->resolveImagePath($image->path);
            
            if (!$imagePath || !file_exists($imagePath)) {
                $this->error("    ⚠️  Image file not found: {$image->path}");
                $this->error("    Tried paths: " . implode(', ', $this->getPossiblePaths($image->path)));
                return false;
            }
            
            $fileSize = round(filesize($imagePath) / 1024, 2);
            $this->line("    📁 File: " . basename($imagePath) . " ({$fileSize} KB)");
            
            // Read file content
            $fileContent = file_get_contents($imagePath);
            if (!$fileContent) {
                $this->error("    ⚠️  Cannot read file content");
                return false;
            }
            
            // First try: Send as photo
            $response = Http::attach(
                'photo', $fileContent, basename($imagePath)
            )->timeout(30)->post("https://api.telegram.org/bot{$botToken}/sendPhoto", [
                'chat_id' => $channelId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
            
            $result = $response->json();
            
            if ($response->successful() && ($result['ok'] ?? false)) {
                return true;
            }
            
            // Second try: If photo fails, try as document
            $this->line("    🔄 Retrying as document...");
            
            $response2 = Http::attach(
                'document', $fileContent, basename($imagePath)
            )->timeout(30)->post("https://api.telegram.org/bot{$botToken}/sendDocument", [
                'chat_id' => $channelId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
            
            $result2 = $response2->json();
            
            if ($response2->successful() && ($result2['ok'] ?? false)) {
                return true;
            }
            
            $errorMsg = $result['description'] ?? $result2['description'] ?? 'Unknown error';
            $this->error("    ❌ Telegram API error: {$errorMsg}");
            
            return false;
            
        } catch (\Exception $e) {
            $this->error("    ❌ Exception: " . $e->getMessage());
            Log::error("Telegram send exception", [
                'image' => $image->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function resolveImagePath($path)
    {
        $possiblePaths = $this->getPossiblePaths($path);
        
        foreach ($possiblePaths as $testPath) {
            if (file_exists($testPath)) {
                return $testPath;
            }
        }
        
        return null;
    }
    
    private function getPossiblePaths($path)
    {
        return [
            storage_path("app/public/{$path}"),
            storage_path("app/{$path}"),
            public_path("storage/{$path}"),
            public_path($path),
            base_path($path),
            $path
        ];
    }
}