<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\CoinTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    /**
     * Get all public products for the store
     */
    public function getPublicProducts(Request $request)
    {
        $query = Product::with(['images', 'room:id,name,owner_id', 'room.owner:id,name,avatar'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->where('visibility', 'public')
            ->where('status', 'active');

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('description', 'like', "%{$searchTerm}%")
                  ->orWhere('category', 'like', "%{$searchTerm}%");
            });
        }

        // Apply category filter
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Apply room filter
        if ($request->has('room_id') && $request->room_id) {
            $query->where('room_id', $request->room_id);
        }

        // Apply price range filter
        if ($request->has('price_min')) {
            $query->where('price', '>=', $request->price_min);
        }
        if ($request->has('price_max')) {
            $query->where('price', '<=', $request->price_max);
        }

        // Apply sorting
        $sortBy = $request->get('sort', 'newest');
        switch ($sortBy) {
            case 'price_low':
                $query->orderBy('price', 'asc');
                break;
            case 'price_high':
                $query->orderBy('price', 'desc');
                break;
            case 'name':
                $query->orderBy('name', 'asc');
                break;
            case 'rating':
                $query->orderByDesc('reviews_avg_rating');
                break;
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $products = $query->paginate(12);

        // Add favorite status for authenticated users
        if (Auth::check()) {
            $userId = Auth::id();
            $products->getCollection()->transform(function ($product) use ($userId) {
                $product->is_liked = $product->isFavoritedBy($userId);
                
                // Calculate average rating properly
                $avgRating = $product->reviews_avg_rating;
                if ($avgRating === null && $product->reviews_count > 0) {
                    // Fallback: calculate manually if withAvg failed
                    $avgRating = $product->reviews()->avg('rating') ?: 0;
                }
                
                $product->average_rating = round($avgRating ?: 0, 1);
                $product->reviews_count = $product->reviews_count ?: 0;
                return $product;
            });
        } else {
            // Add review data for non-authenticated users
            $products->getCollection()->transform(function ($product) {
                // Calculate average rating properly
                $avgRating = $product->reviews_avg_rating;
                if ($avgRating === null && $product->reviews_count > 0) {
                    // Fallback: calculate manually if withAvg failed
                    $avgRating = $product->reviews()->avg('rating') ?: 0;
                }
                
                $product->average_rating = round($avgRating ?: 0, 1);
                $product->reviews_count = $product->reviews_count ?: 0;
                return $product;
            });
        }

        return response()->json($products);
    }

    /**
     * Toggle product visibility between private and public
     */
    public function toggleVisibility(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        
        // Check if user is the owner of the room
        if ($product->room->owner_id !== Auth::id()) {
            return response()->json([
                'error' => 'Only the room owner can change product visibility'
            ], 403);
        }

        $request->validate([
            'visibility' => 'required|in:private,public'
        ]);

        $newVisibility = $request->visibility;
        $currentVisibility = $product->visibility;

        // If changing from private to public, check coins and deduct
        if ($currentVisibility === 'private' && $newVisibility === 'public') {
            $user = Auth::user();
            
            // Check if user has enough coins
            if ($user->coins < 20) {
                return response()->json([
                    'error' => 'Insufficient coins. You need 20 coins to make a product public.',
                    'required_coins' => 20,
                    'current_coins' => $user->coins
                ], 400);
            }

            DB::transaction(function () use ($user, $product) {
                // Deduct coins
                $user->decrement('coins', 20);
                
                // Create transaction record
                CoinTransaction::create([
                    'user_id' => $user->id,
                    'direction' => 'out',
                    'amount' => 20,
                    'source_type' => 'spend',
                    'action' => 'product_visibility_public',
                    'notes' => 'Made product public to show in store'
                ]);
                
                // Update product visibility
                $product->update(['visibility' => 'public']);
            });

            return response()->json([
                'message' => 'Product is now public and visible in the store',
                'product' => $product->fresh()->load(['images', 'room.owner:id,name,avatar']),
                'coins_deducted' => 20,
                'remaining_coins' => $user->fresh()->coins
            ]);
        }

        // If changing from public to private (no cost)
        if ($currentVisibility === 'public' && $newVisibility === 'private') {
            $product->update(['visibility' => 'private']);
            
            return response()->json([
                'message' => 'Product is now private and only visible in the room',
                'product' => $product->fresh()->load(['images', 'room.owner:id,name,avatar'])
            ]);
        }

        // If no change needed
        return response()->json([
            'message' => 'Product visibility unchanged',
            'product' => $product->load(['images', 'room.owner:id,name,avatar'])
        ]);
    }

    /**
     * Get categories for filtering
     */
    public function getCategories()
    {
        $categories = Product::where('visibility', 'public')
            ->where('status', 'active')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->sort()
            ->values();

        return response()->json($categories);
    }

    /**
     * Get rooms that have public products for filtering
     */
    public function getRoomsWithPublicProducts()
    {
        $rooms = Product::with('room:id,name')
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->get()
            ->pluck('room')
            ->unique('id')
            ->values();

        return response()->json($rooms);
    }
}
