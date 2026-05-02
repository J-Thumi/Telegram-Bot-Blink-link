<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'invoice_id',
        'telegram_id',
        'telegram_invite_link',
        'telegram_invite_link_id',
        'invite_sent_at',
        'subject',
        'image_sent_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'invite_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the subject associated with this purchase.
     */
    public function subjectModel(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject', 'name');
    }

    /**
     * Get the invoice associated with this purchase.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Check if the invite link has been sent.
     */
    public function hasInviteLinkSent(): bool
    {
        return !is_null($this->telegram_invite_link) && !is_null($this->invite_sent_at);
    }

    /**
     * Check if the purchase is completed (paid and link sent).
     */
    public function isCompleted(): bool
    {
        return $this->invoice && $this->invoice->isPaid() && $this->hasInviteLinkSent();
    }

    /**
     * Get the Telegram user ID as integer (if needed for APIs).
     */
    public function getTelegramIdIntAttribute(): int
    {
        return (int) $this->telegram_id;
    }

    /**
     * Scope a query to only include purchases for a specific Telegram user.
     */
    public function scopeForTelegramUser($query, string $telegramId)
    {
        return $query->where('telegram_id', $telegramId);
    }

    /**
     * Scope a query to only include completed purchases (paid + link sent).
     */
    public function scopeCompleted($query)
    {
        return $query->whereHas('invoice', function ($q) {
            $q->where('status', Invoice::STATUS_PAID);
        })->whereNotNull('telegram_invite_link')
          ->whereNotNull('invite_sent_at');
    }

    /**
     * Scope a query to only include pending purchases (unpaid).
     */
    public function scopePending($query)
    {
        return $query->whereHas('invoice', function ($q) {
            $q->where('status', Invoice::STATUS_PENDING);
        });
    }
    public function scopePaid($query)
    {
        return $query->whereHas('invoice', function ($q) {
            $q->where('status', Invoice::STATUS_PAID);
        });
    }
    public function scopeExpired($query)
    {
        return $query->whereHas('invoice', function ($q) {
            $q->where('status', Invoice::STATUS_EXPIRED);
        });
    }
    public function scopeCancelled($query)
    {
        return $query->whereHas('invoice', function ($q) {
            $q->where('status', Invoice::STATUS_CANCELLED);
        });
    }
}