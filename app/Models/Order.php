<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'buyer_id',
        'batch_id',
        'parent_order_id',
        'quantity',
        'total_price',
        'phone_number',
        'address',
        'city',
        'delivery_notes',
        'status',
        'placed_from'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'total_price' => 'integer',
    ];

    /**
     * Get the product associated with the order
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the buyer (user) who placed the order
     */
    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * Get the parent order (if this is a line item)
     */
    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    /**
     * Get the child orders (line items of this order)
     */
    public function childOrders()
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    /**
     * Check if this is a main order (not a line item)
     */
    public function isMainOrder()
    {
        return is_null($this->parent_order_id);
    }

    /**
     * Get the main order (either this order if it's main, or its parent)
     */
    public function getMainOrder()
    {
        return $this->isMainOrder() ? $this : $this->parentOrder;
    }

    /**
     * Get all orders in the same batch (if this order is part of a batch)
     */
    public function batchOrders()
    {
        if (!$this->batch_id) {
            return collect([$this]);
        }
        
        return self::where('batch_id', $this->batch_id)
            ->with(['product', 'buyer'])
            ->get();
    }

    /**
     * Check if this order is part of a batch
     */
    public function isPartOfBatch()
    {
        return !is_null($this->batch_id);
    }

    /**
     * Get the primary order for chat (first order in batch or this order if not batched)
     */
    public function getPrimaryOrderForChat()
    {
        if (!$this->batch_id) {
            return $this;
        }
        
        return self::where('batch_id', $this->batch_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Get the messages associated with this order
     */
    public function messages()
    {
        return $this->hasMany(OrderMessage::class);
    }

    /**
     * Get the chat deletions associated with this order
     */
    public function chatDeletions()
    {
        return $this->hasMany(OrderChatDeletion::class);
    }

    /**
     * Check if the order can be cancelled
     */
    public function canBeCancelled()
    {
        // Buyers can cancel pending or accepted orders, but not delivered/rejected/cancelled
        return in_array($this->status, ['pending', 'accepted']);
    }

    /**
     * Check if the order can be shipped
     */
    public function canBeShipped()
    {
        return $this->status === 'accepted';
    }

    /**
     * Check if the order is delivered
     */
    public function isDelivered()
    {
        return $this->status === 'delivered';
    }

    /**
     * Check if chat deletion is allowed for this order
     */
    public function canDeleteChat()
    {
        return in_array($this->status, ['delivered', 'rejected', 'cancelled']);
    }

    /**
     * Check if a user has deleted the chat for this order
     */
    public function isChatDeletedByUser($userId)
    {
        return $this->chatDeletions()->where('user_id', $userId)->exists();
    }

    /**
     * Get the seller user ID
     */
    public function getSellerId()
    {
        return $this->product->room->owner_id;
    }

    /**
     * Check if both buyer and seller have deleted the chat
     */
    public function isChatDeletedByBothParties()
    {
        $buyerId = $this->buyer_id;
        $sellerId = $this->getSellerId();
        
        $deletions = $this->chatDeletions()->whereIn('user_id', [$buyerId, $sellerId])->count();
        
        return $deletions >= 2;
    }
} 