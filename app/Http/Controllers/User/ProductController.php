<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;


class ProductController extends Controller
{
    /**
     * Compress and optimize image using PHP GD
     */
    private function compressImage($file, $quality = 85, $maxWidth = 800, $maxHeight = 600)
    {
        try {
            // Check if GD extension is available
            if (!extension_loaded('gd')) {
                \Log::warning('GD extension not available, using original upload');
                return $file->store('product-images', 'public');
            }

            // Get file info
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();
            
            // Create image resource based on type
            $image = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($file->getPathname());
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($file->getPathname());
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($file->getPathname());
                    break;
                default:
                    // Unsupported format, use original upload
                    return $file->store('product-images', 'public');
            }

            if (!$image) {
                return $file->store('product-images', 'public');
            }

            // Get original dimensions
            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);
            
            // Calculate new dimensions maintaining aspect ratio
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
            
            if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                $newWidth = round($originalWidth * $ratio);
                $newHeight = round($originalHeight * $ratio);
            }
            
            // Create new image with calculated dimensions
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefill($resizedImage, 0, 0, $transparent);
            }
            
            // Resize the image
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Generate unique filename
            $filename = uniqid() . '_' . time() . '.' . $extension;
            $path = 'product-images/' . $filename;
            $fullPath = Storage::disk('public')->path($path);
            
            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Save compressed image
            $success = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $success = imagejpeg($resizedImage, $fullPath, $quality);
                    break;
                case 'image/png':
                    // PNG quality is 0-9 (0 = no compression, 9 = max compression)
                    $pngQuality = round(9 - ($quality / 100) * 9);
                    $success = imagepng($resizedImage, $fullPath, $pngQuality);
                    break;
                case 'image/gif':
                    $success = imagegif($resizedImage, $fullPath);
                    break;
            }
            
            // Clean up memory
            imagedestroy($image);
            imagedestroy($resizedImage);
            
            if ($success) {
                return $path;
            } else {
                return $file->store('product-images', 'public');
            }
            
        } catch (\Exception $e) {
            // Fallback to original upload method if compression fails
            \Log::warning('Image compression failed: ' . $e->getMessage());
            return $file->store('product-images', 'public');
        }
    }
    /**
     * List all products in a room
     */
    public function index($roomId)
    {
        $room = Room::with('owner:id,name,avatar')->findOrFail($roomId);
        
        // Check if room is commercial
        if (!$room->is_commercial) {
            return response()->json([
                'error' => 'This room is not a commercial room'
            ], 403);
        }
        
        $products = $room->products()
            ->with(['images', 'room.owner:id,name,avatar'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->get();
        
        // Add favorite status and review data for authenticated users
        if (Auth::check()) {
            $userId = Auth::id();
            $products = $products->map(function ($product) use ($userId) {
                $product->is_liked = $product->isFavoritedBy($userId);
                $product->average_rating = round($product->reviews_avg_rating ?: 0, 1);
                $product->reviews_count = $product->reviews_count ?: 0;
                return $product;
            });
        } else {
            // Add review data for non-authenticated users
            $products = $products->map(function ($product) {
                $product->average_rating = round($product->reviews_avg_rating ?: 0, 1);
                $product->reviews_count = $product->reviews_count ?: 0;
                return $product;
            });
        }
        
        return response()->json([
            'room' => $room,
            'products' => $products
        ]);
    }

    /**
     * Create a new product with images
     */
    public function store(Request $request, $roomId)
    {
        $room = Room::findOrFail($roomId);
        
        // Check if room is commercial
        if (!$room->is_commercial) {
            return response()->json([
                'error' => 'Products can only be added to commercial rooms'
            ], 403);
        }
        
        // Check if user is the owner of the room
        if ($room->owner_id !== Auth::id()) {
            return response()->json([
                'error' => 'Only the owner of this commercial room can add products'
            ], 403);
        }
        
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|integer|min:0',
            'stock' => 'required|integer|min:0',
            'status' => 'in:active,inactive',
            'category' => 'nullable|string|max:255',
            'images' => 'nullable|array|max:3',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);
        
        $product = Product::create([
            'room_id' => $roomId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'stock' => $request->stock,
            'status' => $request->status ?? 'active',
            'category' => $request->category
        ]);
        
        // Handle image uploads with compression
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $this->compressImage($image);
                
                ProductImage::create([
                    'product_id' => $product->id,
                    'file_path' => $path
                ]);
            }
        }
        
        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['images', 'room.owner:id,name,avatar'])
        ], 201);
    }

    /**
     * Update product details and images
     */
    public function update(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        $room = $product->room;
        
        // Check if user is the owner of the room
        if ($room->owner_id !== Auth::id()) {
            return response()->json([
                'error' => 'Only the owner of this commercial room can update products'
            ], 403);
        }
        
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'price' => 'sometimes|required|integer|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'status' => 'sometimes|in:active,inactive',
            'category' => 'sometimes|nullable|string|max:255',
            'images' => 'sometimes|array|max:3',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'remove_images' => 'sometimes|array',
            'remove_images.*' => 'integer|exists:product_images,id'
        ]);
        
        // Update product details
        $product->update($request->only([
            'name', 'description', 'price', 'stock', 'status', 'category'
        ]));
        
        // Handle image removal
        if ($request->has('remove_images')) {
            $imagesToRemove = ProductImage::where('product_id', $product->id)
                ->whereIn('id', $request->remove_images)
                ->get();
                
            foreach ($imagesToRemove as $image) {
                Storage::disk('public')->delete($image->file_path);
                $image->delete();
            }
        }
        
        // Handle new image uploads
        if ($request->hasFile('images')) {
            // Check total images after upload (existing + new - removed)
            $currentImagesCount = $product->images()->count();
            $newImagesCount = count($request->file('images'));
            $removedImagesCount = $request->has('remove_images') ? count($request->remove_images) : 0;
            $totalAfterUpdate = $currentImagesCount + $newImagesCount - $removedImagesCount;
            
            if ($totalAfterUpdate > 3) {
                return response()->json([
                    'error' => 'Maximum 3 images allowed per product. Current: ' . $currentImagesCount . ', Adding: ' . $newImagesCount . ', Removing: ' . $removedImagesCount
                ], 422);
            }
            
            foreach ($request->file('images') as $image) {
                $path = $this->compressImage($image);
                
                ProductImage::create([
                    'product_id' => $product->id,
                    'file_path' => $path
                ]);
            }
        }
        
        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->fresh()->load(['images', 'room.owner:id,name,avatar'])
        ]);
    }

    /**
     * Delete product
     */
    public function destroy($productId)
    {
        $product = Product::findOrFail($productId);
        $room = $product->room;
        
        // Check if user is the owner of the room
        if ($room->owner_id !== Auth::id()) {
            return response()->json([
                'error' => 'Only the owner of this commercial room can delete products'
            ], 403);
        }
        
        // Delete associated images from storage
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->file_path);
        }
        
        $product->delete();
        
        return response()->json([
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * View product details (for buyers)
     */
    public function show($productId)
    {
        $product = Product::with(['images', 'room.owner:id,name,avatar'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->findOrFail($productId);
        
        // Check if room is commercial
        if (!$product->room->is_commercial) {
            return response()->json([
                'error' => 'This product is not in a commercial room'
            ], 403);
        }
        
        // Check if product is active
        if ($product->status !== 'active') {
            return response()->json([
                'error' => 'This product is not available'
            ], 403);
        }
        
        // Add review data for authenticated users
        $canReview = false;
        if (Auth::check()) {
            $canReview = $product->canBeReviewedBy(Auth::id());
        }
        
        // Add review data to product
        $product->average_rating = round($product->reviews_avg_rating ?: 0, 1);
        $product->reviews_count = $product->reviews_count ?: 0;
        
        return response()->json([
            'product' => $product,
            'can_review' => $canReview,
            'average_rating' => $product->average_rating,
            'reviews_count' => $product->reviews_count
        ]);
    }
}
