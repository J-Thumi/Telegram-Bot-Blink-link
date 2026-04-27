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
}