<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomOnlineMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'last_seen'
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the room this online member belongs to
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * Get the user this online member belongs to
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark user as online in a room
     */
    public static function markUserOnline(int $roomId, int $userId): void
    {
        static::updateOrCreate(
            ['room_id' => $roomId, 'user_id' => $userId],
            ['last_seen' => now()]
        );
    }

    /**
     * Mark user as offline in a room
     */
    public static function markUserOffline(int $roomId, int $userId): void
    {
        static::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Get online members for a room (active within last 8 minutes)
     */
    public static function getOnlineMembers(int $roomId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('room_id', $roomId)
            ->where('last_seen', '>=', now()->subMinutes(8))
            ->with(['user' => function ($query) {
                $query->select('id', 'name', 'avatar', 'email');
            }])
            ->get();
    }

    /**
     * Clean up stale online members (older than 8 minutes)
     */
    public static function cleanupStaleMembers(): int
    {
        return static::where('last_seen', '<', now()->subMinutes(8))->delete();
    }
} 