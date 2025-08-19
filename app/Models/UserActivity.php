<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class UserActivity extends Model
{
    protected $fillable = [
        'user_id',
        'activity_date',
        'total_minutes',
        'last_activity_at'
    ];

    protected $casts = [
        'activity_date' => 'date',
        'last_activity_at' => 'datetime'
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create today's activity record for a user
     */
    public static function getTodaysActivity(int $userId): self
    {
        return self::firstOrCreate([
            'user_id' => $userId,
            'activity_date' => Carbon::today()
        ]);
    }

    /**
     * Update user activity time
     */
    public static function updateActivity(int $userId, int $minutesToAdd = 1): void
    {
        $activity = self::getTodaysActivity($userId);
        
        $activity->total_minutes += $minutesToAdd;
        $activity->last_activity_at = now();
        $activity->save();
    }

    /**
     * Check if user has completed 30 minutes of activity today
     */
    public static function hasCompletedDailyActivity(int $userId): bool
    {
        $activity = self::where('user_id', $userId)
            ->where('activity_date', Carbon::today())
            ->first();

        return $activity && $activity->total_minutes >= 30;
    }

    /**
     * Get user's activity minutes for today
     */
    public static function getTodaysMinutes(int $userId): int
    {
        $activity = self::where('user_id', $userId)
            ->where('activity_date', Carbon::today())
            ->first();

        return $activity ? $activity->total_minutes : 0;
    }
} 