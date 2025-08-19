<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

class ImageController extends Controller
{
    /**
     * Serve product images with optimal cache headers and performance
     */
    public function serveProductImage($filename)
    {
        // Security: Validate filename format
        if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            abort(404, 'Invalid image format');
        }

        // Build the full path
        $path = "product-images/{$filename}";
        
        // Check if file exists in storage
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'Image not found');
        }

        // Get file info
        $fullPath = Storage::disk('public')->path($path);
        $mimeType = Storage::disk('public')->mimeType($path);
        $fileSize = Storage::disk('public')->size($path);
        $lastModified = Storage::disk('public')->lastModified($path);

        // Generate ETag for cache validation
        $etag = md5($filename . $lastModified . $fileSize);

        // Check if client has cached version
        $clientEtag = request()->header('If-None-Match');
        if ($clientEtag && trim($clientEtag, '"') === $etag) {
            return response('', 304, [
                'ETag' => "\"{$etag}\"",
                'Cache-Control' => 'public, max-age=2592000', // 30 days
            ]);
        }

        // Serve the image with optimal cache headers
        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'ETag' => "\"{$etag}\"",
            'Cache-Control' => 'public, max-age=2592000, immutable', // 30 days, immutable
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'Expires' => gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT',
            'Vary' => 'Accept-Encoding',
            // Security headers
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    /**
     * Serve avatar images with cache headers
     */
    public function serveAvatar($filename)
    {
        // Security: Validate filename format
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            abort(404, 'Invalid avatar format');
        }

        $path = "avatars/{$filename}";
        
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'Avatar not found');
        }

        $fullPath = Storage::disk('public')->path($path);
        $mimeType = Storage::disk('public')->mimeType($path);
        $lastModified = Storage::disk('public')->lastModified($path);
        $etag = md5($filename . $lastModified);

        // Check cache
        $clientEtag = request()->header('If-None-Match');
        if ($clientEtag && trim($clientEtag, '"') === $etag) {
            return response('', 304, [
                'ETag' => "\"{$etag}\"",
                'Cache-Control' => 'public, max-age=86400', // 1 day for avatars
            ]);
        }

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'ETag' => "\"{$etag}\"",
            'Cache-Control' => 'public, max-age=86400', // 1 day for avatars
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
} 