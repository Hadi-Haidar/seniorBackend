<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomMember extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'room_id',
        'user_id',
        'role',
        'status',
    ];

    /**
     * Get the room this membership belongs to
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the user this membership belongs to
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the member is a moderator
     */
    public function isModerator()
    {
        return $this->role === 'moderator';
    }

    /**
     * Check if the member is approved
     */
    public function isApproved()
    {
        return $this->status === 'approved';
    }

    /**
     * Check if the member is pending approval
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }
}
