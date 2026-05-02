<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'name', 
        'slug', 
        'description', 
        'color',
        'members_count', // New column for tracking member count
        'status', // New column for tracking Telegram channel status
        'due_date', // New column for tracking when to send images
        'images_sent_at', // New column for tracking when images were sent
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    // Auto-generate slug from name
    protected static function booted()
    {
        static::creating(function ($subject) {
            if (empty($subject->slug)) {
                $subject->slug = \Illuminate\Support\Str::slug($subject->name);
            }
        });
    }
    // App\Models\Subject.php

    public static function getNextDue()
    {
        return self::where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addHours(24))
            ->orderBy('due_date', 'asc')
            ->first();
    }

    public function getCompletedPurchasesCountAttribute(): int
    {
        return $this->purchases()
            ->whereHas('invoice', function ($q) {
                $q->where('status', Invoice::STATUS_PAID);
            })
            ->whereNotNull('telegram_invite_link')
            ->whereNotNull('invite_sent_at')
            ->count();
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'subject', 'name');
    }
}