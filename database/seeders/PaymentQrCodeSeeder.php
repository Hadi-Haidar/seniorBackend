<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\PaymentQrCode;

class PaymentQrCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing data first
        PaymentQrCode::truncate();

        // Create the QR code entry with your uploaded image
        PaymentQrCode::create([
            'qr_image' => '',
            'wish_number' => '81386402',
            'description' => 'WishMoney Payment QR Code - Scan to make payment using wish number 81386402'
        ]);

        

        // $this->command->info('PaymentQrCode seeder completed successfully!');
        // $this->command->info('Created QR code entry with wish number: 81386402');
        // $this->command->info('Image path: qr_codes/W6ghvNu5xCW0k08Idc7mmk52zLkayFpi6nUXDLEw.jpg');
    }
}
