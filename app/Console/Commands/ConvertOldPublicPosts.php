<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ConvertOldPublicPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'posts:convert-old-public';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert public posts older than 24 hours to private visibility';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting conversion of old public posts to private...');
        
        // Calculate the cutoff time (24 hours ago)
        $cutoffTime = Carbon::now()->subHours(24);
        
        $this->info("Converting posts published before: {$cutoffTime->format('Y-m-d H:i:s')}");
        
        // Find public posts older than 24 hours
        $oldPublicPosts = Post::where('visibility', 'public')
            ->where('published_at', '<', $cutoffTime)
            ->whereNotNull('published_at');
        
        // Get count before updating
        $postsToConvert = $oldPublicPosts->count();
        
        if ($postsToConvert === 0) {
            $this->info('No old public posts found to convert.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$postsToConvert} posts to convert...");
        
        // Update posts to private visibility
        $convertedCount = $oldPublicPosts->update([
            'visibility' => 'private'
        ]);
        
        $this->info("Successfully converted {$convertedCount} posts from public to private.");
        
        // Show current public posts count
        $remainingPublicPosts = Post::where('visibility', 'public')->count();
        $this->info("Remaining public posts: {$remainingPublicPosts}");
        
        return Command::SUCCESS;
    }
} 