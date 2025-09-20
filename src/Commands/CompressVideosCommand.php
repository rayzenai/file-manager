<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Jobs\CompressVideoJob;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\VideoCompressionService;

class CompressVideosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'file-manager:compress-videos
                            {--model= : Specific model class to process}
                            {--field= : Specific field to process}
                            {--path= : Specific path/directory to process}
                            {--format=webm : Output format (webm or mp4)}
                            {--bitrate= : Video bitrate in kbps}
                            {--max-width= : Maximum video width}
                            {--max-height= : Maximum video height}
                            {--preset=medium : Encoding preset (ultrafast, fast, medium, slow, veryslow)}
                            {--crf=30 : Constant Rate Factor (0-63, lower is better quality)}
                            {--replace : Replace original files}
                            {--delete-original : Delete original files after compression}
                            {--async : Process videos asynchronously using queue}
                            {--dry-run : Show what would be compressed without actually doing it}
                            {--limit=0 : Limit number of videos to process (0 = no limit)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compress video files to reduce file size using FFmpeg';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $format = $this->option('format');
        $bitrate = $this->option('bitrate') ? (int) $this->option('bitrate') : null;
        $maxWidth = $this->option('max-width') ? (int) $this->option('max-width') : null;
        $maxHeight = $this->option('max-height') ? (int) $this->option('max-height') : null;
        $preset = $this->option('preset');
        $crf = (int) $this->option('crf');
        $replace = $this->option('replace');
        $deleteOriginal = $this->option('delete-original');
        $async = $this->option('async');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('🎬 Video Compression Tool');
        $this->info('========================');

        // Check if FFmpeg is available
        if (! $this->checkFFmpegAvailable()) {
            $this->error('FFmpeg is not installed or not accessible. Please install FFmpeg to use video compression.');
            $this->info('Installation instructions:');
            $this->info('- macOS: brew install ffmpeg');
            $this->info('- Ubuntu/Debian: sudo apt-get install ffmpeg');
            $this->info('- Windows: Download from https://ffmpeg.org/download.html');

            return 1;
        }

        $videos = $this->findVideos();

        if ($videos->isEmpty()) {
            $this->warn('No videos found to compress.');

            return 0;
        }

        $this->info("Found {$videos->count()} video(s) to process.");

        if ($limit > 0) {
            $videos = $videos->take($limit);
            $this->info("Processing limited to {$limit} video(s).");
        }

        if ($dryRun) {
            $this->info('DRY RUN MODE - No actual compression will be performed.');
        }

        $this->newLine();

        // Display compression settings
        $this->table(
            ['Setting', 'Value'],
            [
                ['Format', $format],
                ['Bitrate', $bitrate ? "{$bitrate} kbps" : 'Auto'],
                ['Max Width', $maxWidth ?? 'No limit'],
                ['Max Height', $maxHeight ?? 'No limit'],
                ['Preset', $preset],
                ['CRF', $crf],
                ['Replace Original', $replace ? 'Yes' : 'No'],
                ['Delete Original', $deleteOriginal ? 'Yes' : 'No'],
                ['Async Processing', $async ? 'Yes (Queue)' : 'No (Synchronous)'],
            ]
        );

        $this->newLine();

        if (! $this->confirm('Do you want to proceed with compression?')) {
            $this->info('Compression cancelled.');

            return 0;
        }

        $processed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($videos as $video) {
            $this->processVideo(
                $video,
                $format,
                $bitrate,
                $maxWidth,
                $maxHeight,
                $preset,
                $crf,
                $replace,
                $deleteOriginal,
                $async,
                $dryRun,
                $processed,
                $failed,
                $skipped
            );
        }

        $this->newLine();
        $this->info('Compression Summary:');
        $this->info("✅ Processed: {$processed}");
        $this->info("❌ Failed: {$failed}");
        $this->info("⏭️ Skipped: {$skipped}");

        return 0;
    }

    /**
     * Check if FFmpeg is available
     */
    private function checkFFmpegAvailable(): bool
    {
        $ffmpegPath = config('file-manager.video_compression.ffmpeg_path') ?? 'ffmpeg';
        exec("which {$ffmpegPath} 2>&1", $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Find videos to compress
     */
    private function findVideos()
    {
        if ($this->option('model') && $this->option('field')) {
            // Find videos from specific model/field
            return $this->findVideosFromModel();
        } elseif ($this->option('path')) {
            // Find videos from specific path
            return $this->findVideosFromPath();
        } else {
            // Find all videos from media metadata
            return $this->findAllVideos();
        }
    }

    /**
     * Find videos from a specific model and field
     */
    private function findVideosFromModel()
    {
        $modelClass = $this->option('model');
        $field = $this->option('field');

        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");

            return collect();
        }

        return MediaMetadata::where('mediable_type', $modelClass)
            ->where('mediable_field', $field)
            ->where('mime_type', 'like', 'video/%')
            ->get();
    }

    /**
     * Find videos from a specific path
     */
    private function findVideosFromPath()
    {
        $path = $this->option('path');
        $files = Storage::disk('s3')->files($path);

        $videos = collect();
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg'];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, $videoExtensions)) {
                // Create a pseudo MediaMetadata object
                $videos->push((object) [
                    'file_name' => $file,
                    'mime_type' => $this->guessMimeType($extension),
                    'file_size' => Storage::disk('s3')->size($file),
                ]);
            }
        }

        return $videos;
    }

    /**
     * Find all videos from media metadata
     */
    private function findAllVideos()
    {
        return MediaMetadata::where('mime_type', 'like', 'video/%')->get();
    }

    /**
     * Process a single video
     */
    private function processVideo(
        $video,
        string $format,
        ?int $bitrate,
        ?int $maxWidth,
        ?int $maxHeight,
        string $preset,
        int $crf,
        bool $replace,
        bool $deleteOriginal,
        bool $async,
        bool $dryRun,
        int &$processed,
        int &$failed,
        int &$skipped
    ): void {
        $this->info("Processing: {$video->file_name}");

        // Check if already compressed (has _compressed in filename or is already webm)
        $extension = pathinfo($video->file_name, PATHINFO_EXTENSION);
        if (str_contains($video->file_name, '_compressed') ||
            ($extension === 'webm' && $format === 'webm')) {
            $this->warn('  ⏭️ Skipped (already compressed)');
            $skipped++;

            return;
        }

        if ($dryRun) {
            $this->info("  🔍 Would compress to {$format} format");
            $processed++;

            return;
        }

        try {
            if ($async) {
                // Queue the compression job
                $pathInfo = pathinfo($video->file_name);
                $outputPath = $replace
                    ? null
                    : $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_compressed.' . $format;

                CompressVideoJob::dispatch(
                    $video->file_name,
                    $outputPath,
                    $format,
                    $bitrate,
                    $maxWidth,
                    $maxHeight,
                    $preset,
                    $crf,
                    's3',
                    $video->mediable_type ?? null,
                    $video->mediable_id ?? null,
                    $video->mediable_field ?? null,
                    $replace,
                    $deleteOriginal
                );

                $this->info('  ✅ Queued for compression');
                $processed++;
            } else {
                // Compress synchronously
                $videoService = new VideoCompressionService;

                $pathInfo = pathinfo($video->file_name);
                $outputPath = $replace
                    ? $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.' . $format
                    : $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_compressed.' . $format;

                $result = $videoService->compressAndSave(
                    $video->file_name,
                    $outputPath,
                    $format,
                    $bitrate,
                    $maxWidth,
                    $maxHeight,
                    $preset,
                    $crf,
                    's3'
                );

                if ($result['success']) {
                    $this->info('  ✅ Compressed successfully');
                    $this->info('     Original size: ' . $this->formatBytes($result['data']['original_size']));
                    $this->info('     Compressed size: ' . $this->formatBytes($result['data']['compressed_size']));
                    $this->info("     Compression ratio: {$result['data']['compression_ratio']}");

                    // Handle replace/delete options
                    if ($replace || $deleteOriginal) {
                        Storage::disk('s3')->delete($video->file_name);
                        $this->info('     🗑️ Original deleted');
                    }

                    // Update metadata if available
                    if (isset($video->id)) {
                        $video->update([
                            'file_name' => $replace ? $outputPath : $video->file_name,
                            'mime_type' => 'video/' . $format,
                            'file_size' => $result['data']['compressed_size'],
                            'width' => $result['data']['width'] ?? $video->width,
                            'height' => $result['data']['height'] ?? $video->height,
                        ]);
                    }

                    $processed++;
                } else {
                    $this->error("  ❌ Compression failed: {$result['message']}");
                    $failed++;
                }
            }
        } catch (\Exception $e) {
            $this->error("  ❌ Error: {$e->getMessage()}");
            $failed++;
        }
    }

    /**
     * Guess MIME type from extension
     */
    private function guessMimeType(string $extension): string
    {
        return match ($extension) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
