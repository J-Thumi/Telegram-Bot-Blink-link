<?php

namespace App\Console\Commands;

use App\Models\Subject;
use App\Models\Image;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckSubjectDueImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subjects:check-due-images
                            {--channel-id= : Telegram channel ID (overrides .env)}
                            {--bot-token= : Telegram bot token (overrides .env)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check subjects due in 24 hours and send images to Telegram channel if member count meets requirements';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for subjects due in the next 24 hours...');

        // Get subjects due in 24 hours
        $subjectsDue = $this->getSubjectsDueIn24Hours();

        if ($subjectsDue->isEmpty()) {
            $this->info('No subjects are due in the next 24 hours.');
            Log::info('No subjects due in 24 hours. Command completed.');
            return Command::SUCCESS;
        }

        $this->info("Found {$subjectsDue->count()} subject(s) due in 24 hours.");

        $sentCount = 0;
        
        foreach ($subjectsDue as $subject) {
            $this->info("\nProcessing: {$subject->name}");

            $telegram = new TelegramService();

            $telegram_channel_id = config('services.telegram.channel_id');
            $memberCount = $telegram->getChatMemberCount($telegram_channel_id);

            $this->line("  Current members: {$memberCount}");
            $this->line("  Required members: {$subject->members_count}");

            if ($memberCount < $subject->members_count) {
                $this->warn(" Member requirement NOT met. Need " .($subject->members_count - $memberCount) . " more member(s)");

                Log::info(" Member requirement NOT met for subject: {$subject->name}");
                continue;
            }
        
            if ($memberCount >= $subject->members_needed) {
                $this->info("   ✅ Member requirement met! Sending images...");
                
                // Debug: Check images before sending
                $this->debugImages($subject);
                
                $result = $this->sendSubjectImagesToChannel($subject);
                
                if ($result) {
                    $sentCount++;
                    $this->info("   📸 Images sent successfully!");
                    
                    $subject->update([
                        'images_sent_at' => now(),
                        'last_checked_at' => now(),
                        'status' => 'Sent',
                        'images_sent_at' => now()
                    ]);
                } else {
                    $this->error("   ❌ Failed to send images for {$subject->name}");
                }
            } else {
                $this->warn("   ⚠️  Member requirement NOT met. Need " . 
                           ($subject->members_needed - $memberCount) . " more member(s).");

                $subject->update(['status' => 'pending']);
            }
        }
        
        $this->info("\n🎉 Process completed. Sent images for {$sentCount} subject(s).");
        
        return Command::SUCCESS;
    }

    /**
     * Get subjects that are due in the next 24 hours
     */
    private function getSubjectsDueIn24Hours()
    {
        $now = Carbon::now();
        $next24Hours = Carbon::now()->addHours(24);
        
        return Subject::where('due_date', '>=', $now)
            ->where('due_date', '<=', $next24Hours)
            ->where('status', '!=', 'Sent')
            ->where(function ($query) {
                // Only get subjects that haven't had images sent yet
                $query->whereNull('images_sent_at')
                      ->orWhere('images_sent_at', '<', Carbon::now()->subHours(24));
            })
            ->get();
    }
/**
     * Debug: Show image information
     */
    private function debugImages(Subject $subject)
    {
        $images = $subject->images()->orderBy('sort_order')->get();
        
        $this->line("   📸 Found {$images->count()} image(s):");
        
        foreach ($images as $image) {
            $fullPath = storage_path("app/public/{$image->path}");
            $exists = file_exists($fullPath);
            $size = $exists ? filesize($fullPath) : 0;
            
            $this->line("      - {$image->title}: " . ($exists ? "✓ exists ({$size} bytes)" : "✗ MISSING"));
            
            if (!$exists) {
                $this->warn("        Path tried: {$fullPath}");
                $this->warn("        Database path: {$image->path}");
            }
        }
    }

    private function sendSubjectImagesToChannel(Subject $subject)
    {
        $botToken = $this->option('bot-token') ?? config('services.telegram.bot_token');
        $channelId = $this->option('channel-id') ?? config('services.telegram.channel_id');
        
        if (!$botToken || !$channelId) {
            $this->error("   ❌ Telegram bot token or channel ID not configured!");
            return false;
        }
        
        $images = $subject->images()->orderBy('sort_order')->get();
        
        if ($images->isEmpty()) {
            $this->warn("   ⚠️  No images found for subject: {$subject->name}");
            return false;
        }
        
        $caption = "<b>{$subject->name}</b>\n\n" . ($subject->description ?? '');
        
        // Try sending as separate messages first (more reliable)
        $successCount = 0;
        
        foreach ($images as $index => $image) {
            $imageCaption = $index === 0 ? $caption : null;
            
            if ($this->sendSingleImage($image, $channelId, $botToken, $imageCaption)) {
                $successCount++;
                // Small delay between messages
                usleep(500000); // 0.5 second delay
            } else {
                $this->error("   ❌ Failed to send image: {$image->title}");
            }
        }
        
        return $successCount > 0;
    }

    private function sendSingleImage(Image $image, $channelId, $botToken, $caption = null)
    {
        try {
            // Try different path resolutions
            $imagePath = $this->resolveImagePath($image->path);
            
            if (!$imagePath || !file_exists($imagePath)) {
                $this->error("   ❌ Image file not found: {$image->path}");
                $this->error("      Tried paths: " . json_encode($this->getPossiblePaths($image->path)));
                return false;
            }
            
            $this->line("      Sending: " . basename($imagePath) . " (" . round(filesize($imagePath) / 1024, 2) . " KB)");
            
            // Read file content
            $fileContent = file_get_contents($imagePath);
            if (!$fileContent) {
                $this->error("   ❌ Cannot read file content");
                return false;
            }
            
            // Method 1: Send as photo
            $response = Http::attach(
                'photo', $fileContent, basename($imagePath)
            )->post("https://api.telegram.org/bot{$botToken}/sendPhoto", [
                'chat_id' => $channelId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
            
            $result = $response->json();
            
            if ($response->successful() && ($result['ok'] ?? false)) {
                return true;
            }
            
            // Method 2: If failed, try sending as document
            $this->warn("      Photo method failed, trying as document...");
            
            $response2 = Http::attach(
                'document', $fileContent, basename($imagePath)
            )->post("https://api.telegram.org/bot{$botToken}/sendDocument", [
                'chat_id' => $channelId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
            
            $result2 = $response2->json();
            
            if ($response2->successful() && ($result2['ok'] ?? false)) {
                return true;
            }
            
            $this->error("   ❌ Both methods failed: " . ($result['description'] ?? $result2['description'] ?? 'Unknown error'));
            Log::error("Telegram send failed", [
                'image' => $image->id,
                'path' => $imagePath,
                'response' => $result ?? $result2
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
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