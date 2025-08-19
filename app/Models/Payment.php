<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Payment extends Model
{
    // Constants
    public const PAYMENT_METHOD_WISHMONEY = 'wishmoney';
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const DEFAULT_CURRENCY = 'USD';

    protected $fillable = [
        'user_id',
        'payment_method',
        'payment_status',
        'amount',
        'transaction_id',
        'phone_no',
        'currency',
        'canceled_at',
        'reject_reason',
        'rejected_at'
    ];

    protected $casts = [
        'canceled_at' => 'datetime',
        'amount' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($payment) {
            $payment->payment_method = self::PAYMENT_METHOD_WISHMONEY;
            $payment->currency = self::DEFAULT_CURRENCY;
            
            // Validate amount is between 1-10
            if ($payment->amount < 1 || $payment->amount > 10) {
                throw new \InvalidArgumentException('Payment amount must be between 1 and 10');
            }
        });
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('payment_status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('payment_status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('payment_status', self::STATUS_FAILED);
    }

    // Methods
    public function markAsCompleted(): bool
    {
        $this->payment_status = self::STATUS_COMPLETED;
        
        if ($this->save()) {
            // Add amount to user's balance when payment is completed
            $this->user->addBalance($this->amount);
            return true;
        }
        
        return false;
    }

    public function markAsFailed(): bool
    {
        $this->payment_status = self::STATUS_FAILED;
        return $this->save();
    }

    public function cancel(): bool
    {
        if ($this->payment_status === self::STATUS_PENDING) {
            $this->canceled_at = now();
            $this->payment_status = self::STATUS_FAILED;
            return $this->save();
        }
        return false;
    }

    // Generate unique transaction ID
    public static function generateTransactionId(): string
    {
        return 'WISH_' . time() . '_' . rand(1000, 9999);
    }
}
