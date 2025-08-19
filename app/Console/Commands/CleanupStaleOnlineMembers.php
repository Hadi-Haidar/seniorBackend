<?php

namespace App\Console\Commands;

use App\Services\OnlineMemberService;
use Illuminate\Console\Command;

class CleanupStaleOnlineMembers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'online-members:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale online members (older than 5 minutes)';

    protected $onlineMemberService;

    /**
     * Create a new command instance.
     */
    public function __construct(OnlineMemberService $onlineMemberService)
    {
        parent::__construct();
        $this->onlineMemberService = $onlineMemberService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cleanup of stale online members...');
        
        $cleanedCount = $this->onlineMemberService->cleanupStaleMembers();
        
        $this->info("Cleaned up {$cleanedCount} stale online member records.");
        
        return Command::SUCCESS;
    }
} 