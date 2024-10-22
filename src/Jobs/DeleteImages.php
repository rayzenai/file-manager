<?php

namespace Kirantimsina\FileManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kirantimsina\FileManager\FileManager;

class DeleteImages implements ShouldQueue
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
        FileManager::deleteImagesArray($this->filesArr);
    }
}
