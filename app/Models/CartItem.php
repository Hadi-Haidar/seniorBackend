<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'reserved_stock',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'reserved_stock' => 'integer',
    ];

    /**
     * Get the user that owns the cart item
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product associated with the cart item
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Check if the requested quantity is available
     */
    public function isQuantityAvailable()
    {
        return $this->product->stock >= $this->quantity;
    }

    /**
     * Reserve stock temporarily during checkout process
     */
    public function reserveStockForCheckout()
    {
        $product = $this->product;
        
        if ($product->stock >= $this->quantity) {
            // Update reserved stock
            $this->reserved_stock = $this->quantity;
            $this->save();
            
            // Reduce available stock temporarily
            $product->stock -= $this->quantity;
            $product->save();
            
            return true;
        }
        
        return false;
    }

    /**
     * Release reserved stock (if checkout fails or cart is abandoned)
     */
    public function releaseReservedStock()
    {
        if ($this->reserved_stock > 0) {
            $product = $this->product;
            
            // Return stock to product
            $product->stock += $this->reserved_stock;
            $product->save();
            
            // Clear reserved stock
            $this->reserved_stock = 0;
            $this->save();
        }
    }

    /**
     * Confirm purchase (permanently reduce stock)
     */
    public function confirmPurchase()
    {
        if ($this->reserved_stock > 0) {
            // Stock is already reduced, just clear the reservation
            $this->reserved_stock = 0;
            $this->save();
            return true;
        }
        
        // If not reserved, reduce stock now
        $product = $this->product;
        if ($product->stock >= $this->quantity) {
            $product->stock -= $this->quantity;
            $product->save();
            return true;
        }
        
        return false;
    }

    /**
     * Calculate total price for this cart item
     */
    public function getTotalPriceAttribute()
    {
        return $this->product->price * $this->quantity;
    }
}
