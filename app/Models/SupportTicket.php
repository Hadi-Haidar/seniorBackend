<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_number',
        'user_id',
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'assigned_to',
        'assigned_admin_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot method to generate ticket number
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($ticket) {
            if (!$ticket->ticket_number) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
        });
    }

    /**
     * Generate unique ticket number
     */
    public static function generateTicketNumber()
    {
        do {
            $number = 'TKT-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('ticket_number', $number)->exists());
        
        return $number;
    }

    /**
     * Get the user that owns the ticket
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin assigned to the ticket
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    /**
     * Get all messages for this ticket
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    /**
     * Get the latest message
     */
    public function latestMessage()
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')->latest();
    }

    /**
     * Scope for filtering by status
     */
    public function scopeStatus($query, $status)
    {
        if ($status && $status !== 'All') {
            return $query->where('status', $status);
        }
        return $query;
    }

    /**
     * Scope for filtering by category
     */
    public function scopeCategory($query, $category)
    {
        if ($category && $category !== 'All') {
            return $query->where('category', $category);
        }
        return $query;
    }

    /**
     * Scope for filtering by priority
     */
    public function scopePriority($query, $priority)
    {
        if ($priority && $priority !== 'All') {
            return $query->where('priority', $priority);
        }
        return $query;
    }

    /**
     * Scope for searching (only by ticket number or user)
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'LIKE', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'LIKE', "%{$search}%")
                               ->orWhere('email', 'LIKE', "%{$search}%");
                  });
            });
        }
        return $query;
    }


} 