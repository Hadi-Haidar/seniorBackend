<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserRoomUsage extends Model
{
    use HasFactory;

    protected $table = 'user_room_usage';

    protected $fillable = [
        'user_id',
        'usage_year',
        'usage_month',
        'monthly_rooms_created'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper methods
    public static function getMonthlyUsage($userId, $year = null, $month = null)
    {
        $year = $year ?: now()->year;
        $month = $month ?: now()->month;

        return self::where('user_id', $userId)
                   ->where('usage_year', $year)
                   ->where('usage_month', $month)
                   ->sum('monthly_rooms_created');
    }

    public static function resetMonthlyUsage($userId)
    {
        // Called at the beginning of each month for the user
        self::where('user_id', $userId)
            ->where('usage_year', now()->year)
            ->where('usage_month', now()->month)
            ->delete();
    }
}
