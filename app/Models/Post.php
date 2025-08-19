<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'room_id',
        'user_id',
        'title',
        'content',
        'visibility',
        'is_featured',
        'scheduled_at',
        'published_at'
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * Get the room that owns the post
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the user who created the post
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the media items for the post
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class);
    }

    /**
     * Get the comments for the post
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get the likes for the post
     */
    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    /**
     * Get the reports for the post
     */
    public function reports(): HasMany
    {
        return $this->hasMany(PostReport::class);
    }

    /**
     * Check if a user has liked this post
     */
    public function isLikedBy($userId): bool
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }

    /**
     * Get the total number of likes for this post
     */
    public function getLikesCountAttribute(): int
    {
        return $this->likes()->count();
    }

    public function user(): BelongsTo
    {
        return $this->author();
    }
}
