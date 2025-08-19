<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductFavorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    /**
     * Get user's favorite products
     */
    public function index()
    {
        $favorites = Auth::user()->favoriteProducts()
            ->with(['product.images', 'product.room.owner:id,name,avatar'])
            ->get();

        return response()->json([
            'favorites' => $favorites->map(function ($favorite) {
                return [
                    'id' => $favorite->id,
                    'product' => $favorite->product,
                    'created_at' => $favorite->created_at
                ];
            })
        ]);
    }

    /**
     * Toggle favorite status for a product
     */
    public function toggle(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $product = Product::findOrFail($request->product_id);

        // Check if product is active
        if ($product->status !== 'active') {
            return response()->json([
                'error' => 'This product is not available'
            ], 400);
        }

        $favorite = ProductFavorite::where('user_id', Auth::id())
            ->where('product_id', $request->product_id)
            ->first();

        if ($favorite) {
            // Remove from favorites
            $favorite->delete();
            $isFavorited = false;
            $message = 'Product removed from favorites';
        } else {
            // Add to favorites
            ProductFavorite::create([
                'user_id' => Auth::id(),
                'product_id' => $request->product_id
            ]);
            $isFavorited = true;
            $message = 'Product added to favorites';
        }

        return response()->json([
            'message' => $message,
            'is_favorited' => $isFavorited
        ]);
    }

    /**
     * Remove product from favorites
     */
    public function destroy($favoriteId)
    {
        $favorite = ProductFavorite::where('id', $favoriteId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $favorite->delete();

        return response()->json([
            'message' => 'Product removed from favorites'
        ]);
    }

    /**
     * Check if product is favorited by user
     */
    public function check($productId)
    {
        $isFavorited = ProductFavorite::where('user_id', Auth::id())
            ->where('product_id', $productId)
            ->exists();

        return response()->json([
            'is_favorited' => $isFavorited
        ]);
    }
}
