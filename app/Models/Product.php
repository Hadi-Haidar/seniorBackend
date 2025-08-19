<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'room_id',
        'name',
        'description',
        'price',
        'stock',
        'status',
        'category',
        'visibility',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
    ];

    /**
     * Get the room that owns the product
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the images for the product
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * Get all orders for this product
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get all cart items for this product
     */
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Get all favorites for this product
     */
    public function favorites()
    {
        return $this->hasMany(ProductFavorite::class);
    }

    /**
     * Get all reviews for this product
     */
    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Check if product is favorited by a specific user
     */
    public function isFavoritedBy($userId)
    {
        return $this->favorites()->where('user_id', $userId)->exists();
    }

    /**
     * Check if a user can review this product
     * User can review if they have a delivered order for this product and haven't reviewed yet
     * OR if they purchased the product (regardless of delivery status) for easier testing
     */
    public function canBeReviewedBy($userId)
    {
        // Check if user has any order for this product (not just delivered)
        // This makes it easier for users to review products they've purchased
        $hasPurchased = $this->orders()
            ->where('buyer_id', $userId)
            ->whereIn('status', ['delivered', 'confirmed', 'shipped'])
            ->exists();

        if (!$hasPurchased) {
            return false;
        }

        // Check if user hasn't already reviewed this product
        $hasReviewed = $this->reviews()
            ->where('user_id', $userId)
            ->exists();

        return !$hasReviewed;
    }

    /**
     * Get average rating for this product
     */
    public function getAverageRatingAttribute()
    {
        $rating = $this->reviews()->avg('rating');
        return $rating ? round((float)$rating, 1) : 0;
    }

    /**
     * Get total review count
     */
    public function getReviewsCountAttribute()
    {
        return $this->reviews()->count();
    }

    /**
     * Get rating distribution (count for each star rating)
     */
    public function getRatingDistribution()
    {
        return $this->reviews()
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get()
            ->keyBy('rating')
            ->map(fn($item) => $item->count);
    }

    /**
     * Get available stock for display (actual stock shown to users)
     * This is the stock that appears to be available for purchase
     */
    public function getAvailableStockAttribute()
    {
        // The stock field already reflects reserved quantities
        // so we can return it directly
        return $this->stock;
    }

    /**
     * Get total reserved stock across all carts
     */
    public function getTotalReservedStock()
    {
        return $this->cartItems()->sum('reserved_stock');
    }
} 