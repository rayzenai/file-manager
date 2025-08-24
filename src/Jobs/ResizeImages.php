<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kirantimsina\FileManager\FileManagerService;

class ResizeImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    protected $filesArr;

    public function __construct($filesArr)
    {
        $this->filesArr = $filesArr;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Skip if no image sizes are configured
        $sizes = FileManagerService::getImageSizes();
        if (empty($sizes)) {
            return;
        }

        foreach ($this->filesArr as $file) {
            FileManagerService::resizeImage($file, false);
        }
    }
}
