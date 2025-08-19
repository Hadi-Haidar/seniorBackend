<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;  // Add this import
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;  // Add HasApiTokens trait

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',  
        'avatar', 
        'bio',
        'email_verification_token',  // Add this for email verification
        'email_verification_token_created_at',
        'subscription_level',
        'user_type',  // Add user_type to fillable
        'balance',
        'coins'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_token',  // Hide token for security
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance' => 'decimal:2',
        ];
    }

    /**
     * Get all cart items for the user
     */
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Get all favorite products for the user
     */
    public function favoriteProducts()
    {
        return $this->hasMany(ProductFavorite::class);
    }

    /**
     * Get the user's coin transactions
     */
    public function coinTransactions()
    {
        return $this->hasMany(CoinTransaction::class);
    }

    /**
     * Get the user activities for tracking.
     */
    public function userActivities()
    {
        return $this->hasMany(UserActivity::class);
    }

    /**
     * Get rooms owned by the user
     */
    public function ownedRooms()
    {
        return $this->hasMany(Room::class, 'owner_id');
    }

    /**
     * Get room memberships for the user
     */
    public function roomMembers()
    {
        return $this->hasMany(RoomMember::class);
    }

    /**
     * Get all rooms the user is a member of
     */
    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'room_members')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * Get all orders made by the user
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    /**
     * Get all order messages sent by the user
     */
    public function orderMessages()
    {
        return $this->hasMany(OrderMessage::class, 'sender_id');
    }

    /**
     * Get all posts created by the user
     */
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get all comments made by the user
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get all payments for the user
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get all subscriptions for the user
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the current active subscription
     */
    public function currentSubscription()
    {
        return $this->subscriptions()
            ->active()
            ->latest()
            ->first();
    }

    /**
     * Check if user has an active subscription
     */
    public function hasActiveSubscription(): bool
    {
        return $this->currentSubscription() !== null;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->user_type === 'admin';
    }

    /**
     * Generate a consistent default avatar identifier for users without avatars
     * This creates a deterministic avatar based on user's name and ID
     */
    public function generateDefaultAvatar(): string
    {
        // Use name or email to generate a consistent seed
        $seed = $this->name ?: $this->email;
        
        // Simple hash function to generate a number from string
        $hash = 0;
        for ($i = 0; $i < strlen($seed); $i++) {
            $char = ord($seed[$i]);
            $hash = (($hash << 5) - $hash) + $char;
            $hash = $hash & $hash; // Convert to 32bit integer
        }
        
        // Array of nice colors for avatars (matching frontend)
        $colors = [
            'blue', 'green', 'purple', 'pink', 'indigo', 
            'red', 'yellow', 'teal', 'orange', 'cyan'
        ];
        
        // Use hash to select a color
        $colorIndex = abs($hash) % count($colors);
        $color = $colors[$colorIndex];
        
        // Get user initials
        $initials = $this->getUserInitials();
        
        // Create a default avatar identifier that the frontend can recognize
        // Format: "default:color:initials"
        return "default:{$color}:{$initials}";
    }
    
    /**
     * Get user initials for avatar display
     */
    private function getUserInitials(): string
    {
        if (!$this->name || trim($this->name) === '') {
            return '?';
        }
        
        // Split name by spaces and filter out empty strings
        $nameParts = array_filter(explode(' ', trim($this->name)));
        
        if (empty($nameParts)) {
            return '?';
        }
        
        // Always return just the first letter of the first name
        return strtoupper(substr($nameParts[0], 0, 1));
    }

    /**
     * Get the user's subscription level from active subscription or default
     */
    public function getEffectiveSubscriptionLevel(): string
    {
        $activeSubscription = $this->currentSubscription();
        
        if ($activeSubscription) {
            return $activeSubscription->level;
        }
        
        return $this->subscription_level ?? 'bronze';
    }

    /**
     * Add balance to user account
     */
    public function addBalance(float $amount): bool
    {
        $this->balance += $amount;
        return $this->save();
    }

    /**
     * Deduct balance from user account
     */
    public function deductBalance(float $amount): bool
    {
        if ($this->balance >= $amount) {
            $this->balance -= $amount;
            return $this->save();
        }
        
        return false;
    }

    /**
     * Add coins to user account
     */
    public function addCoins(int $amount): bool
    {
        $this->coins += $amount;
        return $this->save();
    }

    /**
     * Deduct coins from user account
     */
    public function deductCoins(int $amount): bool
    {
        if ($this->coins >= $amount) {
            $this->coins -= $amount;
            return $this->save();
        }
        
        return false;
    }

    /**
     * Get chat rooms where user is a participant
     */
    public function chatRooms()
    {
        return $this->belongsToMany(ChatRoom::class, 'chat_room_participants', 'user_id', 'room_id')
            ->withTimestamps();
    }

    /**
     * Get all chat messages sent by the user
     */
    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }



    /**
     * Get all direct messages sent by the user
     */
    public function sentDirectMessages()
    {
        return $this->hasMany(DirectMessage::class, 'sender_id');
    }

    /**
     * Get all direct messages received by the user
     */
    public function receivedDirectMessages()
    {
        return $this->hasMany(DirectMessage::class, 'receiver_id');
    }

    /**
     * Get all direct messages involving the user (sent or received)
     */
    public function allDirectMessages()
    {
        return DirectMessage::where('sender_id', $this->id)
            ->orWhere('receiver_id', $this->id)
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get all notifications for the user
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadNotificationsCountAttribute(): int
    {
        return $this->notifications()->unread()->count();
    }

}