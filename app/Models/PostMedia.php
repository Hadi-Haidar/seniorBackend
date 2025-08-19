<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    protected $fillable = [
        'post_id',
        'media_type',
        'file_path'
    ];

    protected $casts = [
        'media_type' => 'string'
    ];

    /**
     * Get the post that owns the media
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
