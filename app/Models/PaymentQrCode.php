<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentQrCode extends Model
{
    protected $fillable = [
        'qr_image',
        'wish_number',
        'description'
    ];

    /**
     * Get the QR image URL
     */
    public function getQrImageUrlAttribute()
    {
        return $this->qr_image ? asset('storage/' . $this->qr_image) : null;
    }

    /**
     * Get the active QR code (first available record)
     */
    public static function getActiveQrCode()
    {
        return self::first();
    }
}
