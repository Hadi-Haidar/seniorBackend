<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class OrderMessage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'sender_id',
        'type',
        'message',
        'file_path'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['file_url'];

    /**
     * Get the order that owns the message
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who sent the message
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get the URL for the file if it exists
     */
    public function getFileUrlAttribute()
    {
        if ($this->file_path) {
            return Storage::url($this->file_path);
        }
        return null;
    }

    /**
     * Delete the file from storage when the message is deleted
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($message) {
            if ($message->file_path) {
                Storage::disk('public')->delete($message->file_path);
            }
        });
    }
} 