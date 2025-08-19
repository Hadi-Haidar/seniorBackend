<?php

namespace App\Console\Commands;

use App\Models\UserRoomUsage;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CleanRoomUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'room-usage:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old room usage records older than 3 months';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting room usage cleanup...');
        
        // Calculate the cutoff date (3 months ago)
        $cutoffDate = Carbon::now()->subMonths(3);
        
        $this->info("Removing room usage records older than: {$cutoffDate->format('Y-m-d H:i:s')}");
        
        // Delete old room usage records
        $deletedCount = UserRoomUsage::where('created_at', '<', $cutoffDate)->delete();
        
        $this->info("Successfully cleaned up {$deletedCount} old room usage records.");
        
        // Show remaining records count
        $remainingCount = UserRoomUsage::count();
        $this->info("Remaining room usage records: {$remainingCount}");
        
        return Command::SUCCESS;
    }
} 