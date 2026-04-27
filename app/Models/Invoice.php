<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'blink_id',
        'payment_hash',
        'payment_request',
        'amount_msat',
        'status',
        'full_name',
        'username',
        'telegram_client_ip',
        'blink_client_ip',
        'satoshis_paid',
        'paid_at',
        
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_msat' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Status constants for better code readability
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get the purchase associated with this invoice.
     */
    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class);
    }

    /**
     * Check if the invoice is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Check if the invoice is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the invoice is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    /**
     * Mark the invoice as paid.
     */
    public function markAsPaid(): self
    {
        $this->status = self::STATUS_PAID;
        $this->save();

        return $this;
    }

    /**
     * Mark the invoice as expired.
     */
    public function markAsExpired(): self
    {
        $this->status = self::STATUS_EXPIRED;
        $this->save();

        return $this;
    }

    /**
     * Get amount in satoshis (from millisatoshis).
     */
    public function getAmountInSatoshisAttribute(): float
    {
        return $this->amount_msat / 1000;
    }

    /**
     * Get a human-readable amount with currency.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount_in_satoshis, 0) . ' sats';
    }

    /**
     * Scope a query to only include pending invoices.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include paid invoices.
     */
    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /**
     * Scope a query to only include expired invoices.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }
}