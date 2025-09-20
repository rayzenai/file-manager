<?php

namespace Kirantimsina\FileManager\Services;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\WebM;
use FFMpeg\Format\Video\X264;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class VideoCompressionService
{
    private string $compressionMethod;

    private string $outputFormat;

    private string $videoCodec;

    private string $audioCodec;

    private int $videoBitrate;

    private int $audioBitrate;

    private ?int $maxWidth;

    private ?int $maxHeight;

    private int $frameRate;

    private string $preset;

    private int $crf;

    private bool $twoPass;

    private int $threads;

    private ?string $ffmpegPath;

    private ?string $ffprobePath;

    private int $timeout;

    private bool $generateThumbnail;

    private float $thumbnailTime;

    public function __construct()
    {
        // Load configuration
        $this->compressionMethod = config('file-manager.video_compression.method', 'ffmpeg');
        $this->outputFormat = config('file-manager.video_compression.format', 'webm');
        $this->videoCodec = config('file-manager.video_compression.video_codec', 'libvpx-vp9');
        $this->audioCodec = config('file-manager.video_compression.audio_codec', 'libopus');
        $this->videoBitrate = config('file-manager.video_compression.video_bitrate', 1000); // kbps
        $this->audioBitrate = config('file-manager.video_compression.audio_bitrate', 128); // kbps
        $this->maxWidth = config('file-manager.video_compression.max_width', 1920);
        $this->maxHeight = config('file-manager.video_compression.max_height', 1080);
        $this->frameRate = config('file-manager.video_compression.frame_rate', 30);
        $this->preset = config('file-manager.video_compression.preset', 'medium');
        $this->crf = config('file-manager.video_compression.crf', 30);
        $this->twoPass = config('file-manager.video_compression.two_pass', false);
        $this->threads = config('file-manager.video_compression.threads', 4);
        $this->ffmpegPath = config('file-manager.video_compression.ffmpeg_path');
        $this->ffprobePath = config('file-manager.video_compression.ffprobe_path');
        $this->timeout = config('file-manager.video_compression.timeout', 3600); // 1 hour default
        $this->generateThumbnail = config('file-manager.video_compression.generate_thumbnail', true);
        $this->thumbnailTime = config('file-manager.video_compression.thumbnail_time', 1.0);
    }

    /**
     * Compress a video file
     */
    public function compress(
        $video,
        ?string $outputFormat = null,
        ?int $videoBitrate = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?string $preset = null,
        ?int $crf = null
    ): array {
        $outputFormat = $outputFormat ?? $this->outputFormat;
        $videoBitrate = $videoBitrate ?? $this->videoBitrate;
        $maxWidth = $maxWidth ?? $this->maxWidth;
        $maxHeight = $maxHeight ?? $this->maxHeight;
        $preset = $preset ?? $this->preset;
        $crf = $crf ?? $this->crf;

        if ($this->compressionMethod === 'api') {
            return $this->compressViaApi($video, $outputFormat, $videoBitrate, $maxWidth, $maxHeight);
        }

        return $this->compressViaFFmpeg($video, $outputFormat, $videoBitrate, $maxWidth, $maxHeight, $preset, $crf);
    }

    /**
     * Compress using FFmpeg
     */
    protected function compressViaFFmpeg(
        $video,
        string $outputFormat,
        int $videoBitrate,
        ?int $maxWidth,
        ?int $maxHeight,
        string $preset,
        int $crf
    ): array {
        try {
            // Check if FFmpeg is available
            if (! $this->isFFmpegAvailable()) {
                return [
                    'success' => false,
                    'message' => 'FFmpeg is not installed or not accessible. Please install FFmpeg to use video compression. On macOS: brew install ffmpeg',
                ];
            }
            // Get video file content
            $fileContent = $this->getFileContent($video);
            if (! $fileContent['success']) {
                return $fileContent;
            }

            // Save to temporary file for FFmpeg processing
            $tempInputPath = tempnam(sys_get_temp_dir(), 'video_input_');
            file_put_contents($tempInputPath, $fileContent['data']['content']);

            // Determine file extension from original filename
            $originalExtension = pathinfo($fileContent['data']['filename'], PATHINFO_EXTENSION);
            rename($tempInputPath, $tempInputPath . '.' . $originalExtension);
            $tempInputPath = $tempInputPath . '.' . $originalExtension;

            // Create FFmpeg instance
            $ffmpegConfig = [
                'timeout' => $this->timeout,
                'ffmpeg.threads' => $this->threads,
            ];

            if ($this->ffmpegPath) {
                $ffmpegConfig['ffmpeg.binaries'] = $this->ffmpegPath;
            }
            if ($this->ffprobePath) {
                $ffmpegConfig['ffprobe.binaries'] = $this->ffprobePath;
            }

            $ffmpeg = FFMpeg::create($ffmpegConfig);
            $ffprobe = FFProbe::create($ffmpegConfig);

            // Open video
            $videoFile = $ffmpeg->open($tempInputPath);

            // Get original video information
            $videoStream = $ffprobe->streams($tempInputPath)->videos()->first();
            $originalWidth = $videoStream->get('width');
            $originalHeight = $videoStream->get('height');
            $originalDuration = $videoStream->get('duration');
            $originalBitrate = $videoStream->get('bit_rate');
            $originalSize = filesize($tempInputPath);

            // Calculate dimensions maintaining aspect ratio
            $dimensions = $this->calculateDimensions($originalWidth, $originalHeight, $maxWidth, $maxHeight);

            // Prepare output format
            if ($outputFormat === 'webm') {
                $format = new WebM;

                // Use VP9 for better compression or VP8 for compatibility
                if ($this->videoCodec === 'libvpx-vp9') {
                    // VP9 with Vorbis audio (most compatible)
                    $format->setVideoCodec('libvpx-vp9');
                    $format->setAudioCodec('libvorbis');

                    // VP9 specific optimizations
                    $format->setAdditionalParameters([
                        '-row-mt', '1', // Enable row-based multithreading
                        '-tile-columns', '2', // Tile columns for parallel encoding
                        '-tile-rows', '1', // Tile rows for parallel encoding
                        '-cpu-used', '1', // Speed/quality trade-off (0-8, lower is slower/better)
                        '-auto-alt-ref', '1', // Enable alternate reference frames
                        '-lag-in-frames', '25', // Look-ahead frames
                        '-b:v', $videoBitrate . 'k',
                        '-crf', (string) $crf, // Constant Rate Factor (0-63, lower is better quality)
                    ]);
                } else {
                    // VP8 with Vorbis audio for maximum compatibility
                    $format->setVideoCodec('libvpx');
                    $format->setAudioCodec('libvorbis');
                    $format->setKiloBitrate($videoBitrate);
                }

                $format->setAudioKiloBitrate($this->audioBitrate);
            } else {
                // H.264 in MP4 container (fallback)
                $format = new X264;
                $format->setKiloBitrate($videoBitrate);
                $format->setAudioKiloBitrate($this->audioBitrate);

                // H.264 specific optimizations
                $format->setAdditionalParameters([
                    '-preset', $preset, // ultrafast, fast, medium, slow, veryslow
                    '-crf', (string) $crf, // Constant Rate Factor (0-51, lower is better quality)
                    '-movflags', '+faststart', // Enable fast start for web streaming
                    '-pix_fmt', 'yuv420p', // Ensure compatibility
                ]);
            }

            // Apply filters (resize, frame rate)
            $filters = [];

            // Resize if needed
            if ($dimensions['width'] !== $originalWidth || $dimensions['height'] !== $originalHeight) {
                $filters[] = [
                    'filter' => 'scale',
                    'options' => [
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ],
                ];
            }

            // Limit frame rate
            if ($this->frameRate > 0) {
                $filters[] = [
                    'filter' => 'fps',
                    'options' => [
                        'fps' => $this->frameRate,
                    ],
                ];
            }

            if (! empty($filters)) {
                foreach ($filters as $filter) {
                    $videoFile->filters()->custom($filter['filter'] . '=' .
                        implode(':', array_values($filter['options'])));
                }
            }

            // Set output file path
            $tempOutputPath = tempnam(sys_get_temp_dir(), 'video_output_') . '.' . $outputFormat;

            // Perform encoding
            $videoFile->save($format, $tempOutputPath);

            // Generate thumbnail if enabled
            $thumbnailData = null;
            if ($this->generateThumbnail) {
                try {
                    $thumbnailPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';
                    $videoFile->frame(TimeCode::fromSeconds($this->thumbnailTime))
                        ->save($thumbnailPath);

                    if (file_exists($thumbnailPath)) {
                        $thumbnailData = base64_encode(file_get_contents($thumbnailPath));
                        unlink($thumbnailPath);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to generate video thumbnail: ' . $e->getMessage());
                }
            }

            // Read compressed video
            $compressedContent = file_get_contents($tempOutputPath);
            $compressedSize = strlen($compressedContent);
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);

            // Clean up temporary files
            unlink($tempInputPath);
            unlink($tempOutputPath);

            return [
                'success' => true,
                'data' => [
                    'compressed_video' => $compressedContent,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'filename' => pathinfo($fileContent['data']['filename'], PATHINFO_FILENAME) . '.' . $outputFormat,
                    'format' => $outputFormat,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'duration' => $originalDuration,
                    'video_bitrate' => $videoBitrate,
                    'audio_bitrate' => $this->audioBitrate,
                    'compression_method' => 'ffmpeg',
                    'thumbnail' => $thumbnailData,
                ],
                'message' => 'Video compressed successfully using FFmpeg',
            ];

        } catch (Throwable $t) {
            // Clean up temporary files if they exist
            if (isset($tempInputPath) && file_exists($tempInputPath)) {
                unlink($tempInputPath);
            }
            if (isset($tempOutputPath) && file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            Log::error('Video compression failed: ' . $t->getMessage());

            return [
                'success' => false,
                'message' => 'Video compression failed: ' . $t->getMessage(),
            ];
        }
    }

    /**
     * Compress via external API (placeholder for future implementation)
     */
    protected function compressViaApi(
        $video,
        string $outputFormat,
        int $videoBitrate,
        ?int $maxWidth,
        ?int $maxHeight
    ): array {
        // This could be implemented to use cloud-based video encoding services
        // like AWS MediaConvert, Coconut, Zencoder, etc.
        return [
            'success' => false,
            'message' => 'API-based video compression not yet implemented. Please use FFmpeg method.',
        ];
    }

    /**
     * Compress and save video to storage
     */
    public function compressAndSave(
        $video,
        string $outputPath,
        ?string $outputFormat = null,
        ?int $videoBitrate = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?string $preset = null,
        ?int $crf = null,
        string $disk = 's3'
    ): array {
        try {
            $result = $this->compress($video, $outputFormat, $videoBitrate, $maxWidth, $maxHeight, $preset, $crf);

            if (! $result['success']) {
                return $result;
            }

            // Determine content type based on format
            $contentType = match ($result['data']['format']) {
                'webm' => 'video/webm',
                'mp4' => 'video/mp4',
                'avi' => 'video/x-msvideo',
                'mov' => 'video/quicktime',
                default => 'video/' . $result['data']['format'],
            };

            // Prepare storage options
            $storageOptions = [
                'visibility' => 'public',
                'ContentType' => $contentType,
            ];

            // Add cache headers if enabled
            if ($disk === 's3' && config('file-manager.cache.enabled', true)) {
                $cacheControl = $this->buildCacheControlHeader();
                if ($cacheControl) {
                    $storageOptions['CacheControl'] = $cacheControl;
                }
            }

            // Save compressed video
            $saved = Storage::disk($disk)->put(
                $outputPath,
                $result['data']['compressed_video'],
                $storageOptions
            );

            if (! $saved) {
                return [
                    'success' => false,
                    'message' => 'Failed to save compressed video to storage',
                ];
            }

            // Save thumbnail if generated
            if (! empty($result['data']['thumbnail'])) {
                $thumbnailPath = str_replace('.' . $result['data']['format'], '_thumb.jpg', $outputPath);
                Storage::disk($disk)->put(
                    $thumbnailPath,
                    base64_decode($result['data']['thumbnail']),
                    array_merge($storageOptions, ['ContentType' => 'image/jpeg'])
                );
                $result['data']['thumbnail_path'] = $thumbnailPath;
            }

            $result['data']['storage_path'] = $outputPath;
            $result['data']['storage_url'] = Storage::disk($disk)->url($outputPath);

            return $result;

        } catch (Throwable $t) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $t->getMessage(),
            ];
        }
    }

    /**
     * Calculate video dimensions maintaining aspect ratio
     */
    private function calculateDimensions(
        int $originalWidth,
        int $originalHeight,
        ?int $maxWidth,
        ?int $maxHeight
    ): array {
        // If no limits set, return original dimensions
        if (! $maxWidth && ! $maxHeight) {
            return [
                'width' => $originalWidth,
                'height' => $originalHeight,
            ];
        }

        // Set defaults if only one dimension is specified
        $maxWidth = $maxWidth ?? PHP_INT_MAX;
        $maxHeight = $maxHeight ?? PHP_INT_MAX;

        // Calculate aspect ratio
        $aspectRatio = $originalWidth / $originalHeight;

        // Check if resizing is needed
        if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
            return [
                'width' => $originalWidth,
                'height' => $originalHeight,
            ];
        }

        // Calculate new dimensions maintaining aspect ratio
        if ($originalWidth / $maxWidth > $originalHeight / $maxHeight) {
            // Width is the limiting factor
            $newWidth = $maxWidth;
            $newHeight = (int) round($maxWidth / $aspectRatio);
        } else {
            // Height is the limiting factor
            $newHeight = $maxHeight;
            $newWidth = (int) round($maxHeight * $aspectRatio);
        }

        // Ensure dimensions are even (required for many video codecs)
        $newWidth = $newWidth % 2 === 0 ? $newWidth : $newWidth - 1;
        $newHeight = $newHeight % 2 === 0 ? $newHeight : $newHeight - 1;

        return [
            'width' => $newWidth,
            'height' => $newHeight,
        ];
    }

    /**
     * Get file content from various input types
     */
    private function getFileContent($video): array
    {
        try {
            // Handle TemporaryUploadedFile (Livewire)
            if ($video instanceof TemporaryUploadedFile) {
                $realPath = $video->getRealPath();
                if (! file_exists($realPath)) {
                    $tempPath = $video->path();
                    if (file_exists($tempPath)) {
                        $realPath = $tempPath;
                    } else {
                        return [
                            'success' => false,
                            'message' => 'Temporary file not found or already moved',
                        ];
                    }
                }

                return [
                    'success' => true,
                    'data' => [
                        'content' => file_get_contents($realPath),
                        'filename' => $video->getClientOriginalName(),
                    ],
                ];
            }

            // Handle UploadedFile
            if ($video instanceof UploadedFile) {
                return [
                    'success' => true,
                    'data' => [
                        'content' => file_get_contents($video->getRealPath()),
                        'filename' => $video->getClientOriginalName(),
                    ],
                ];
            }

            // Handle file path from storage
            if (is_string($video)) {
                // Check if it's a storage path
                if (Storage::disk('s3')->exists($video)) {
                    return [
                        'success' => true,
                        'data' => [
                            'content' => Storage::disk('s3')->get($video),
                            'filename' => basename($video),
                        ],
                    ];
                }

                // Check if it's a local file path
                if (file_exists($video)) {
                    return [
                        'success' => true,
                        'data' => [
                            'content' => file_get_contents($video),
                            'filename' => basename($video),
                        ],
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'File not found: ' . $video,
                ];
            }

            return [
                'success' => false,
                'message' => 'Invalid video input type',
            ];

        } catch (Throwable $t) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $t->getMessage(),
            ];
        }
    }

    /**
     * Build the Cache-Control header value from config
     */
    protected function buildCacheControlHeader(): ?string
    {
        if (! config('file-manager.cache.enabled', true)) {
            return null;
        }

        $visibility = config('file-manager.cache.visibility', 'public');
        $maxAge = config('file-manager.cache.max_age', 31536000);
        $immutable = config('file-manager.cache.immutable', true);

        $header = "{$visibility}, max-age={$maxAge}";

        if ($immutable) {
            $header .= ', immutable';
        }

        return $header;
    }

    /**
     * Check if FFmpeg is available on the system
     */
    public function isFFmpegAvailable(): bool
    {
        $ffmpegPath = $this->ffmpegPath ?? 'ffmpeg';
        $command = escapeshellcmd($ffmpegPath) . ' -version 2>&1';
        exec($command, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Get video metadata without compression
     */
    public function getVideoMetadata($video): array
    {
        try {
            $fileContent = $this->getFileContent($video);
            if (! $fileContent['success']) {
                return $fileContent;
            }

            // Save to temporary file for FFProbe
            $tempPath = tempnam(sys_get_temp_dir(), 'video_meta_');
            file_put_contents($tempPath, $fileContent['data']['content']);

            $ffprobeConfig = [];
            if ($this->ffprobePath) {
                $ffprobeConfig['ffprobe.binaries'] = $this->ffprobePath;
            }

            $ffprobe = FFProbe::create($ffprobeConfig);

            $videoStream = $ffprobe->streams($tempPath)->videos()->first();
            $audioStream = $ffprobe->streams($tempPath)->audios()->first();
            $format = $ffprobe->format($tempPath);

            unlink($tempPath);

            return [
                'success' => true,
                'data' => [
                    'width' => $videoStream ? $videoStream->get('width') : null,
                    'height' => $videoStream ? $videoStream->get('height') : null,
                    'duration' => $format->get('duration'),
                    'bitrate' => $format->get('bit_rate'),
                    'size' => $format->get('size'),
                    'format' => $format->get('format_name'),
                    'video_codec' => $videoStream ? $videoStream->get('codec_name') : null,
                    'audio_codec' => $audioStream ? $audioStream->get('codec_name') : null,
                    'frame_rate' => $videoStream ? eval('return ' . $videoStream->get('r_frame_rate') . ';') : null,
                ],
            ];
        } catch (Throwable $t) {
            return [
                'success' => false,
                'message' => 'Failed to get video metadata: ' . $t->getMessage(),
            ];
        }
    }
}
