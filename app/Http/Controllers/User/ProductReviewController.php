<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Events\ProductRatingUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProductReviewController extends Controller
{
    /**
     * Get all reviews for a specific product
     */
    public function index($productId)
    {
        $product = Product::findOrFail($productId);
        
        $reviews = $product->reviews()
            ->withUser()
            ->latest()
            ->paginate(10);

        $averageRating = $product->average_rating;
        $reviewsCount = $product->reviews_count;
        $ratingDistribution = $product->getRatingDistribution();

        return response()->json([
            'reviews' => $reviews,
            'average_rating' => round($averageRating, 1),
            'reviews_count' => $reviewsCount,
            'rating_distribution' => $ratingDistribution
        ]);
    }

    /**
     * Check if user can review a product
     */
    public function canReview($productId)
    {
        $product = Product::findOrFail($productId);
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'can_review' => false,
                'reason' => 'User not authenticated'
            ]);
        }

        $canReview = $product->canBeReviewedBy($user->id);
        
        $reason = null;
        if (!$canReview) {
            // Check specific reasons
            $hasDeliveredOrder = $product->orders()
                ->where('buyer_id', $user->id)
                ->where('status', 'delivered')
                ->exists();

            if (!$hasDeliveredOrder) {
                $reason = 'You must purchase and receive this product before reviewing';
            } else {
                $hasReviewed = $product->reviews()
                    ->where('user_id', $user->id)
                    ->exists();
                
                if ($hasReviewed) {
                    $reason = 'You have already reviewed this product';
                }
            }
        }

        return response()->json([
            'can_review' => $canReview,
            'reason' => $reason
        ]);
    }

    /**
     * Store a new review
     */
    public function store(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        $user = Auth::user();

        // Check if user can review this product
        if (!$product->canBeReviewedBy($user->id)) {
            return response()->json([
                'error' => 'You are not eligible to review this product'
            ], 403);
        }

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
        ]);

        try {
            $review = ProductReview::create([
                'product_id' => $productId,
                'user_id' => $user->id,
                'rating' => $request->rating,
                'review_text' => $request->review_text,
            ]);

            $review->load('user:id,name,avatar');

            // Refresh product to get updated rating and count
            $product = $product->fresh();

            // Fire real-time rating update event
            event(new ProductRatingUpdated($product, 'created', $review->id, $user->id));

            return response()->json([
                'message' => 'Review submitted successfully',
                'review' => $review,
                'new_average_rating' => round($product->average_rating, 1),
                'new_reviews_count' => $product->reviews_count
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to submit review'
            ], 500);
        }
    }

    /**
     * Update an existing review
     */
    public function update(Request $request, $productId, $reviewId)
    {
        $review = ProductReview::where('id', $reviewId)
            ->where('product_id', $productId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
        ]);

        try {
            $review->update([
                'rating' => $request->rating,
                'review_text' => $request->review_text,
            ]);

            $review->load('user:id,name,avatar');
            $product = $review->product->fresh();

            // Fire real-time rating update event
            event(new ProductRatingUpdated($product, 'updated', $review->id, Auth::id()));

            return response()->json([
                'message' => 'Review updated successfully',
                'review' => $review,
                'new_average_rating' => round($product->average_rating, 1),
                'new_reviews_count' => $product->reviews_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update review'
            ], 500);
        }
    }

    /**
     * Delete a review
     */
    public function destroy($productId, $reviewId)
    {
        $review = ProductReview::where('id', $reviewId)
            ->where('product_id', $productId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        try {
            $product = $review->product;
            $reviewId = $review->id;
            $userId = $review->user_id;
            
            $review->delete();

            // Refresh product to get updated rating and count
            $product = $product->fresh();

            // Fire real-time rating update event
            event(new ProductRatingUpdated($product, 'deleted', $reviewId, $userId));

            return response()->json([
                'message' => 'Review deleted successfully',
                'new_average_rating' => round($product->average_rating, 1),
                'new_reviews_count' => $product->reviews_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete review'
            ], 500);
        }
    }

    /**
     * Get user's review for a product (if exists)
     */
    public function getUserReview($productId)
    {
        $review = ProductReview::where('product_id', $productId)
            ->where('user_id', Auth::id())
            ->with('user:id,name,avatar')
            ->first();

        return response()->json([
            'review' => $review
        ]);
    }
}
