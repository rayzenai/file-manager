<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\VideoCompressionService;

class CompressVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 7200; // 2 hours for video processing

    protected string $videoPath;

    protected ?string $outputPath;

    protected ?string $outputFormat;

    protected ?int $videoBitrate;

    protected ?int $maxWidth;

    protected ?int $maxHeight;

    protected ?string $preset;

    protected ?int $crf;

    protected string $disk;

    protected ?string $modelClass;

    protected ?int $modelId;

    protected ?string $modelField;

    protected bool $replaceOriginal;

    protected bool $deleteOriginal;

    public function __construct(
        string $videoPath,
        ?string $outputPath = null,
        ?string $outputFormat = null,
        ?int $videoBitrate = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?string $preset = null,
        ?int $crf = null,
        ?string $disk = null,
        ?string $modelClass = null,
        ?int $modelId = null,
        ?string $modelField = null,
        bool $replaceOriginal = false,
        bool $deleteOriginal = false
    ) {
        $this->videoPath = $videoPath;
        $this->outputPath = $outputPath;
        $this->outputFormat = $outputFormat;
        $this->videoBitrate = $videoBitrate;
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->preset = $preset;
        $this->crf = $crf;
        $this->disk = $disk ?: config('filesystems.default');
        $this->modelClass = $modelClass;
        $this->modelId = $modelId;
        $this->modelField = $modelField;
        $this->replaceOriginal = $replaceOriginal;
        $this->deleteOriginal = $deleteOriginal;
        $this->onQueue(config('file-manager.queue.videos', 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting video compression for: {$this->videoPath}");

            $videoCompressionService = new VideoCompressionService;

            // Get path info for the video
            $pathInfo = pathinfo($this->videoPath);

            // Determine output path if not provided
            if (! $this->outputPath) {
                $outputFormat = $this->outputFormat ?? config('file-manager.video_compression.format', 'webm');

                // Extract the base filename (remove any existing timestamp/random suffixes)
                $filename = $pathInfo['filename'];
                // Remove existing timestamp pattern (e.g., -1734567890-AbC123)
                $filename = preg_replace('/-\d{10}-[a-zA-Z0-9]{6}$/', '', $filename);

                // Generate a new unique suffix
                $timestamp = time();
                $randomString = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
                $this->outputPath = $pathInfo['dirname'] . '/' . $filename . '-' . $timestamp . '-' . $randomString . '.' . $outputFormat;
            }

            // Use a temp path for compression to avoid conflicts
            $actualOutputPath = $pathInfo['dirname'] . '/temp_' . uniqid() . '.' . ($this->outputFormat ?? 'webm');

            // Compress and save the video
            $result = $videoCompressionService->compressAndSave(
                $this->videoPath,
                $actualOutputPath,
                $this->outputFormat,
                $this->videoBitrate,
                $this->maxWidth,
                $this->maxHeight,
                $this->preset,
                $this->crf,
                $this->disk ?: config('filesystems.default')
            );

            if (! $result['success']) {
                Log::error("Video compression failed for {$this->videoPath}: " . $result['message']);

                return;
            }

            Log::info('Video compressed successfully', [
                'original_size' => $result['data']['original_size'],
                'compressed_size' => $result['data']['compressed_size'],
                'compression_ratio' => $result['data']['compression_ratio'],
                'output_path' => $actualOutputPath,
            ]);

            // Handle replace original logic
            if ($this->replaceOriginal) {
                // Move the compressed file to final location (always different name now)
                Storage::disk($this->disk ?: config('filesystems.default'))->move($actualOutputPath, $this->outputPath);

                // Verify the file was saved successfully
                if (! Storage::disk($this->disk ?: config('filesystems.default'))->exists($this->outputPath)) {
                    Log::error('Failed to save compressed video', [
                        'temp_path' => $actualOutputPath,
                        'final_path' => $this->outputPath,
                    ]);
                    throw new \Exception('Failed to save compressed video');
                }

                // Delete the original video since we have a new compressed one
                Storage::disk($this->disk ?: config('filesystems.default'))->delete($this->videoPath);

                // Update model if provided
                $modelUpdated = false;
                if ($this->modelClass && $this->modelId && $this->modelField) {
                    $modelUpdated = $this->updateModel($this->outputPath, $result);
                }

                // Update or create media metadata
                $this->updateMediaMetadata($this->outputPath, $result, true);

                Log::info('Video compressed and original replaced', [
                    'original' => $this->videoPath,
                    'compressed' => $this->outputPath,
                    'model_updated' => $modelUpdated ? 'yes' : 'no',
                ]);
            } else {
                // For non-replace mode, keep both files and try to update the model
                // Move compressed file to its final location
                Storage::disk($this->disk ?: config('filesystems.default'))->move($actualOutputPath, $this->outputPath);

                // Try to update the model with the compressed path
                $modelUpdated = false;
                if ($this->modelClass && $this->modelField) {
                    // Try to find and update the model
                    $modelUpdated = $this->updateModel($this->outputPath, $result);
                }

                // Only delete original if model was successfully updated and deletion was requested
                if ($modelUpdated && $this->deleteOriginal) {
                    Storage::disk($this->disk ?: config('filesystems.default'))->delete($this->videoPath);
                    Log::info("Original video deleted after successful model update: {$this->videoPath}");
                }

                // Update or create media metadata for new file
                $this->updateMediaMetadata($this->outputPath, $result, false);

                Log::info('Video compressed (non-replace mode)', [
                    'original' => $this->videoPath,
                    'compressed' => $this->outputPath,
                    'model_updated' => $modelUpdated ? 'yes' : 'no',
                    'original_deleted' => ($modelUpdated && $this->deleteOriginal) ? 'yes' : 'no',
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Video compression job failed: ' . $e->getMessage(), [
                'video_path' => $this->videoPath,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Update the model with the new video path
     */
    protected function updateModel(string $newPath, array $compressionResult): bool
    {
        try {
            $model = null;

            // Try multiple methods to find the model
            if ($this->modelId) {
                // If we have an ID, use it
                $model = $this->modelClass::find($this->modelId);
            }

            // If no model found yet and we have the class and field, try to find by video path
            if (! $model && $this->modelClass && $this->modelField) {
                // Normalize paths for comparison (remove leading slashes)
                $normalizedVideoPath = ltrim($this->videoPath, '/');

                // Try to find the model that has this video path (check both with and without leading slash)
                $model = $this->modelClass::where($this->modelField, $normalizedVideoPath)
                    ->orWhere($this->modelField, '/' . $normalizedVideoPath)
                    ->first();

                if ($model) {
                    $this->modelId = $model->id;
                    Log::info('Found model by video path', [
                        'model_class' => $this->modelClass,
                        'model_id' => $model->id,
                        'field' => $this->modelField,
                        'path' => $this->videoPath,
                        'normalized_path' => $normalizedVideoPath,
                    ]);
                } else {
                    // As a last resort, try to find the most recent model with this field value
                    // This helps when multiple entities might have the same video temporarily
                    $model = $this->modelClass::where($this->modelField, $normalizedVideoPath)
                        ->orWhere($this->modelField, '/' . $normalizedVideoPath)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if ($model) {
                        $this->modelId = $model->id;
                        Log::info('Found model by video path (most recent)', [
                            'model_class' => $this->modelClass,
                            'model_id' => $model->id,
                            'field' => $this->modelField,
                            'path' => $this->videoPath,
                            'normalized_path' => $normalizedVideoPath,
                        ]);
                    }
                }
            }

            if (! $model) {
                Log::warning('Model not found for video compression update', [
                    'model_class' => $this->modelClass,
                    'model_id' => $this->modelId,
                    'field' => $this->modelField,
                    'path' => $this->videoPath,
                ]);

                return false;
            }

            // Update the field with new path (ensure no leading slash for S3)
            $normalizedNewPath = ltrim($newPath, '/');
            $fieldValue = $model->{$this->modelField};

            if (is_array($fieldValue)) {
                // Handle array of videos - need to find the old path (with or without leading slash)
                $normalizedVideoPath = ltrim($this->videoPath, '/');
                $index = array_search($normalizedVideoPath, $fieldValue);
                if ($index === false) {
                    // Try with leading slash
                    $index = array_search('/' . $normalizedVideoPath, $fieldValue);
                }
                if ($index !== false) {
                    $fieldValue[$index] = $normalizedNewPath;
                    $model->{$this->modelField} = $fieldValue;
                }
            } else {
                // Single video field
                $model->{$this->modelField} = $normalizedNewPath;
            }

            $model->save();

            Log::info('Model updated with compressed video path', [
                'model_class' => $this->modelClass,
                'model_id' => $this->modelId ?? $model->id,
                'field' => $this->modelField,
                'new_path' => $newPath,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update model with compressed video: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Update or create media metadata for the compressed video
     */
    protected function updateMediaMetadata(string $videoPath, array $compressionResult, bool $isReplacement): void
    {
        try {
            if (! config('file-manager.media_metadata.enabled', true)) {
                return;
            }

            // If we have model information, try to find existing metadata
            if ($this->modelClass && $this->modelId && $this->modelField) {
                $query = MediaMetadata::where('mediable_type', $this->modelClass)
                    ->where('mediable_id', $this->modelId)
                    ->where('mediable_field', $this->modelField);

                if ($isReplacement) {
                    // Update existing metadata for the original file
                    $metadata = $query->where('file_name', $this->videoPath)->first();
                } else {
                    // Check if metadata already exists for the new file
                    $metadata = $query->where('file_name', $videoPath)->first();
                }

                $metadataArray = [
                    'compression_method' => $compressionResult['data']['compression_method'],
                    'original_size' => $compressionResult['data']['original_size'],
                    'compression_ratio' => $compressionResult['data']['compression_ratio'],
                    'video_codec' => $compressionResult['data']['format'] === 'webm' ? 'vp9' : 'h264',
                    'audio_codec' => $compressionResult['data']['format'] === 'webm' ? 'opus' : 'aac',
                ];

                if (! empty($compressionResult['data']['thumbnail_path'])) {
                    $metadataArray['thumbnail'] = $compressionResult['data']['thumbnail_path'];
                }

                if ($metadata) {
                    // Update existing metadata
                    $metadata->update([
                        'file_name' => $videoPath,
                        'mime_type' => 'video/' . $compressionResult['data']['format'],
                        'file_size' => $compressionResult['data']['compressed_size'],
                        'width' => $compressionResult['data']['width'],
                        'height' => $compressionResult['data']['height'],
                        'metadata' => array_merge($metadata->metadata ?? [], $metadataArray),
                    ]);
                } else {
                    // Create new metadata
                    MediaMetadata::create([
                        'mediable_type' => $this->modelClass,
                        'mediable_id' => $this->modelId,
                        'mediable_field' => $this->modelField,
                        'file_name' => $videoPath,
                        'mime_type' => 'video/' . $compressionResult['data']['format'],
                        'file_size' => $compressionResult['data']['compressed_size'],
                        'width' => $compressionResult['data']['width'],
                        'height' => $compressionResult['data']['height'],
                        'metadata' => $metadataArray,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to update media metadata for compressed video: ' . $e->getMessage());
        }
    }

    /**
     * The job failed to process.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Video compression job permanently failed', [
            'video_path' => $this->videoPath,
            'exception' => $exception->getMessage(),
        ]);
    }
}
