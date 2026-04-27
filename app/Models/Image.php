<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    protected $fillable = [
        'subject_id',
        'title',
        'path',
        'thumbnail',
        'description',
        'alt_text',
        'sort_order'
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    // Accessor for full image URL
    public function getImageUrlAttribute(): string
    {
        return asset('storage/' . $this->path);
    }

    // Accessor for thumbnail URL
    public function getThumbnailUrlAttribute(): string
    {
        return $this->thumbnail 
            ? asset('storage/' . $this->thumbnail) 
            : $this->image_url;
    }
}