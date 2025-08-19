<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'file_path',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'image_url',
    ];

    /**
     * Get the URL for the image
     *
     * @return string
     */
    public function getImageUrlAttribute()
    {
        return Storage::url($this->file_path);
    }

    /**
     * Get the product that owns the image
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Delete the image file from storage when the model is deleted
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($productImage) {
            Storage::disk('public')->delete($productImage->file_path);
        });
    }

    /**
     * Check if the file exists in storage
     *
     * @return bool
     */
    public function fileExists()
    {
        return Storage::disk('public')->exists($this->file_path);
    }

    /**
     * Get the file size in bytes
     *
     * @return int|false
     */
    public function getFileSize()
    {
        return Storage::disk('public')->size($this->file_path);
    }

    /**
     * Get the file mime type
     *
     * @return string|false
     */
    public function getMimeType()
    {
        return Storage::disk('public')->mimeType($this->file_path);
    }
} 