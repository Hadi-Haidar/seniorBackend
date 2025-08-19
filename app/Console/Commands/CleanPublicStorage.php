<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanPublicStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:clean-public';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleans all images and data in the public storage directory, keeping the directory structure and .gitignore files.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting public storage cleanup...');

        $directories = [
            'posts',
            'room_images',
            'chat',
            'direct-messages',
            'order-messages',
            'support_attachments',
            'qr_codes',
            'product-images',
            'avatars',
            'chat-messages',
        ];

        $public_storage_path = storage_path('app/public');

        foreach ($directories as $directory) {
            $path = $public_storage_path . '/' . $directory;

            if (File::isDirectory($path)) {
                // Delete all files in the directory
                $files = File::files($path);
                foreach ($files as $file) {
                    if ($file->getFilename() !== '.gitignore') {
                        File::delete($file->getPathname());
                    }
                }

                // Delete all subdirectories
                $subdirectories = File::directories($path);
                foreach ($subdirectories as $subdirectory) {
                    File::deleteDirectory($subdirectory);
                }

                $this->info("Cleaned directory: {$directory}");
            } else {
                $this->warn("Directory not found, skipping: {$directory}");
            }
        }
        
        // Clean root of public storage, but keep .gitignore and directories.
        $root_files = File::files($public_storage_path);
        foreach ($root_files as $file) {
            if ($file->getFilename() !== '.gitignore') {
                File::delete($file->getPathname());
                $this->info("Deleted root file: " . $file->getFilename());
            }
        }

        $this->info('Public storage cleanup complete.');
        return 0;
    }
}
