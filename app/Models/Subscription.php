<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'level',
        'start_date',
        'end_date',
        'is_active'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean'
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', now());
    }

    // Accessors
    public function getIsExpiredAttribute(): bool
    {
        return $this->end_date < now();
    }

    public function getDaysRemainingAttribute(): int
    {
        if ($this->is_expired) {
            return 0;
        }
        return max(0, now()->diffInDays($this->end_date, false));
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->days_remaining <= 7 && !$this->is_expired;
    }

    // Methods
    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }

    public function extend(int $days): bool
    {
        $this->end_date = $this->end_date->addDays($days);
        return $this->save();
    }

    public static function createSubscription(array $data): self
    {
        // Set default dates if not provided
        if (!isset($data['start_date'])) {
            $data['start_date'] = now();
        }
        if (!isset($data['end_date'])) {
            $data['end_date'] = Carbon::parse($data['start_date'])->addMonth();
        }

        return self::create($data);
    }
}
